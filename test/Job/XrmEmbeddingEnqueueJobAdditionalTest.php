<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of MissionBayXrm for BASE3 Framework.
 *
 * MissionBayXrm integrates XRM content with MissionBay agent workflows.
 * Its pipeline indexes XRM sysentries into a vector database.
 * Incremental updates, deduplication and deletions are coordinated.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de
 **********************************************************************/

namespace Base3\MissionBayXrm\Test\Job;

use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\Test\State\StateStoreStub;
use Base3\MissionBayXrm\Job\XrmEmbeddingEnqueueJob;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Base3\MissionBayXrm\Job\XrmEmbeddingEnqueueJob
 */
final class XrmEmbeddingEnqueueJobAdditionalTest extends TestCase {

	private const LAST_CHANGED_KEY = 'missionbay.xrm.embedding.sysentry.last_changed';
	private const LAST_RUN_KEY = 'missionbay.xrm.embedding.sysentry.last_run_at';

	public function testWorkerMetadata(): void {
		$db = $this->createStub(IDatabase::class);
		$job = new XrmEmbeddingEnqueueJob($db, new StateStoreStub());
		$this->assertSame('xrmembeddingenqueuejob', XrmEmbeddingEnqueueJob::getName());
		$this->assertTrue($job->isActive());
		$this->assertSame(1, $job->getPriority());
	}

	public function testEmptyQueueInitializesBothTablesWithoutAdvancingChangedCursor(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], $reads, $writes);
		$state = new StateStoreStub();

		$this->assertSame('Enqueue done - changed: 0, deletes: 0', (new XrmEmbeddingEnqueueJob($db, $state))->go());
		$this->assertCount(2, $reads);
		$this->assertCount(2, $writes);
		$this->assertStringContainsString('CREATE TABLE IF NOT EXISTS base3_embedding_job', $writes[0]);
		$this->assertStringContainsString('CREATE TABLE IF NOT EXISTS base3_embedding_seen', $writes[1]);
		$this->assertTrue($state->has(self::LAST_RUN_KEY));
		$this->assertFalse($state->has(self::LAST_CHANGED_KEY));
	}

	public function testRecentLastRunPreventsScanButPreservesSchemaSetup(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], $reads, $writes);
		$state = new StateStoreStub();
		$lastRun = date('Y-m-d H:i:s', time() - 120);
		$state->set(self::LAST_RUN_KEY, $lastRun);

		$this->assertSame('Skip (min interval not reached)', (new XrmEmbeddingEnqueueJob($db, $state))->go());
		$this->assertSame([], $reads);
		$this->assertCount(2, $writes);
		$this->assertSame($lastRun, $state->get(self::LAST_RUN_KEY));
	}

	public function testExpiredIntervalScansAndUpdatesBothCheckpoints(): void {
		$reads = [];
		$writes = [];
		$state = new StateStoreStub();
		$state->set(self::LAST_RUN_KEY, date('Y-m-d H:i:s', time() - 1200));
		$state->set(self::LAST_CHANGED_KEY, '2026-01-01 00:00:00');
		$db = $this->database([
			['uuid' => 'uuid-1', 'etag' => 'etag-1', 'changed' => '2026-01-03 12:00:00', 'type_alias' => 'article']
		], [], $reads, $writes);

		$this->assertSame('Enqueue done - changed: 1, deletes: 0', (new XrmEmbeddingEnqueueJob($db, $state))->go());
		$this->assertSame('2026-01-03 12:00:00', $state->get(self::LAST_CHANGED_KEY));
		$this->assertStringContainsString("e.changed > '2026-01-01 00:00:00'", $reads[0]);
		$this->assertStringContainsString('LIMIT 5000', $reads[0]);
		$this->assertStringContainsString('LIMIT 2000', $reads[1]);
		$this->assertStringContainsString("'article'", $this->findWrite($writes, "'upsert', 'pending'"));
	}

	public function testInvalidRunTimestampAndEmptyCursorUseDefaults(): void {
		$reads = [];
		$writes = [];
		$state = new StateStoreStub();
		$state->set(self::LAST_RUN_KEY, 'unparseable timestamp');
		$state->set(self::LAST_CHANGED_KEY, '  ');

		$this->assertSame('Enqueue done - changed: 0, deletes: 0', (new XrmEmbeddingEnqueueJob($this->database([], [], $reads, $writes), $state))->go());
		$this->assertStringContainsString("e.changed > '1970-01-01 00:00:00'", $reads[0]);
		$this->assertSame('  ', $state->get(self::LAST_CHANGED_KEY));
	}

	public function testIncompleteChangedRowsAreSkippedAndMaxTimestampIsPersisted(): void {
		$reads = [];
		$writes = [];
		$state = new StateStoreStub();
		$state->set(self::LAST_CHANGED_KEY, '2025-01-01 00:00:00');
		$rows = [
			['uuid' => '', 'etag' => 'e', 'changed' => '2027-01-01 00:00:00'],
			['uuid' => 'a', 'etag' => '', 'changed' => '2027-02-01 00:00:00'],
			['uuid' => 'b', 'etag' => 'v', 'changed' => ''],
			['uuid' => 'c', 'etag' => 'v1', 'changed' => '2026-03-01 00:00:00', 'type_alias' => '  news  '],
			['uuid' => 'd', 'etag' => 'v2', 'changed' => '2026-02-01 00:00:00', 'type_alias' => ''],
		];

		$this->assertSame('Enqueue done - changed: 2, deletes: 0', (new XrmEmbeddingEnqueueJob($this->database($rows, [], $reads, $writes), $state))->go());
		$this->assertSame('2026-03-01 00:00:00', $state->get(self::LAST_CHANGED_KEY));
		$this->assertCount(8, $writes);
		$this->assertStringContainsString("'news'", $this->findWrite($writes, "'upsert', 'pending'"));
		$this->assertStringContainsString("'default'", $this->findWrite($writes, "'upsert', 'pending'", 1));
	}

	public function testAllInvalidRowsDoNotAdvanceTheCursor(): void {
		$reads = [];
		$writes = [];
		$state = new StateStoreStub();
		$state->set(self::LAST_CHANGED_KEY, '2026-01-01 00:00:00');
		$rows = [['uuid' => '', 'etag' => 'missing', 'changed' => '2026-02-01 00:00:00']];

		$this->assertSame('Enqueue done - changed: 0, deletes: 0', (new XrmEmbeddingEnqueueJob($this->database($rows, [], $reads, $writes), $state))->go());
		$this->assertSame('2026-01-01 00:00:00', $state->get(self::LAST_CHANGED_KEY));
		$this->assertCount(2, $writes);
	}

	public function testDeletionIgnoresInvalidRowsAndDoesNotInventMissingJobId(): void {
		$reads = [];
		$writes = [];
		$deleted = [
			['source_uuid' => '', 'last_seen_version' => 'version'],
			['source_uuid' => 'uuid-2', 'last_seen_version' => ''],
			['source_uuid' => 'uuid-3', 'last_seen_version' => 'version-3', 'last_seen_collection_key' => 'articles']
		];

		$this->assertSame('Enqueue done - changed: 0, deletes: 1', (new XrmEmbeddingEnqueueJob($this->database([], $deleted, $reads, $writes), new StateStoreStub()))->go());
		$this->assertCount(4, $writes);
		$this->assertStringContainsString('missing_since = NOW()', $writes[2]);
		$this->assertStringContainsString("'articles'", $writes[3]);
		$this->assertFalse(str_contains(implode('\n', $writes), 'delete_job_id ='));
	}

	public function testDeletionUsesLastSeenCollectionAndExistingJobId(): void {
		$reads = [];
		$writes = [];
		$deleted = [['source_uuid' => 'uuid-delete', 'last_seen_version' => 'version', 'last_seen_collection_key' => '  news  ']];
		$db = $this->database([], $deleted, $reads, $writes, ['job_id' => '47']);

		$this->assertSame('Enqueue done - changed: 0, deletes: 1', (new XrmEmbeddingEnqueueJob($db, new StateStoreStub()))->go());
		$this->assertCount(5, $writes);
		$this->assertStringContainsString("'news'", $writes[3]);
		$this->assertStringContainsString('delete_job_id = 47', $writes[4]);
		$this->assertStringContainsString('collection_key', $reads[2]);
	}

	public function testSqlEscapingIsAppliedToCollectionKeysAndEntryVersions(): void {
		$reads = [];
		$writes = [];
		$rows = [['uuid' => 'u\'uid', 'etag' => 'v\'ersion', 'changed' => '2026-01-03 00:00:00', 'type_alias' => ' news\'type ']];
		$db = $this->database($rows, [], $reads, $writes);
		$this->assertSame('Enqueue done - changed: 1, deletes: 0', (new XrmEmbeddingEnqueueJob($db, new StateStoreStub()))->go());
		$upsert = $this->findWrite($writes, "'upsert', 'pending'");
		$this->assertStringContainsString("u\\'uid", $upsert);
		$this->assertStringContainsString("v\\'ersion", $upsert);
		$this->assertStringContainsString("news\\'type", $upsert);
	}

	private function database(array $changed, array $deleted, array &$reads, array &$writes, ?array $deleteJobRow = null): IDatabase {
		$db = $this->createStub(IDatabase::class);
		$db->method('connected')->willReturn(true);
		$db->method('escape')->willReturnCallback(static fn(string $s): string => addslashes($s));
		$db->method('nonQuery')->willReturnCallback(static function(string $sql) use (&$writes): void {
			$writes[] = $sql;
		});
		$db->method('multiQuery')->willReturnCallback(static function(string $sql) use (&$reads, $changed, $deleted): array {
			$reads[] = $sql;
			if (str_contains($sql, 'FROM base3system_sysentry e')) {
				return $changed;
			}
			if (str_contains($sql, 'FROM base3_embedding_seen s')) {
				return $deleted;
			}
			return [];
		});
		$db->method('singleQuery')->willReturnCallback(static function(string $sql) use ($deleteJobRow, &$reads): ?array {
			$reads[] = $sql;
			return str_contains($sql, "job_type = 'delete'") ? $deleteJobRow : null;
		});
		return $db;
	}

	private function findWrite(array $writes, string $needle, int $occurrence = 0): string {
		foreach ($writes as $sql) {
			if (!str_contains($sql, $needle)) {
				continue;
			}
			if ($occurrence-- > 0) {
				continue;
			}
			return $sql;
		}
		$this->fail('Expected SQL fragment was not executed: ' . $needle);
	}
}
