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
final class XrmEmbeddingEnqueueJobBoundaryTest extends TestCase {

	private const CHANGED_KEY = 'missionbay.xrm.embedding.sysentry.last_changed';
	private const LAST_RUN_KEY = 'missionbay.xrm.embedding.sysentry.last_run_at';

	public function testFutureCheckpointSkipsScanningWithoutMutatingSavedState(): void {
		$reads = [];
		$writes = [];
		$state = new StateStoreStub();
		$state->set(self::LAST_RUN_KEY, '2099-01-01 00:00:00');
		$state->set(self::CHANGED_KEY, '2026-01-03 00:00:00');
		$db = $this->database([], [], $reads, $writes);

		$this->assertSame('Skip (min interval not reached)', (new XrmEmbeddingEnqueueJob($db, $state))->go());
		$this->assertSame([], $reads);
		$this->assertCount(2, $writes);
		$this->assertSame('2099-01-01 00:00:00', $state->get(self::LAST_RUN_KEY));
		$this->assertSame('2026-01-03 00:00:00', $state->get(self::CHANGED_KEY));
	}

	public function testCheckpointNeverRegressesWhenRowsAreReturnedOutOfOrder(): void {
		$reads = [];
		$writes = [];
		$state = new StateStoreStub();
		$state->set(self::CHANGED_KEY, '2026-05-10 12:00:00');
		$db = $this->database([
			['uuid' => 'binary-id-a', 'etag' => 'binary-version-a', 'changed' => '2026-05-08 00:00:00', 'type_alias' => 'invoice'],
			['uuid' => 'binary-id-b', 'etag' => 'binary-version-b', 'changed' => '2026-05-09 00:00:00', 'type_alias' => 'invoice']
		], [], $reads, $writes);

		$this->assertSame('Enqueue done - changed: 2, deletes: 0', (new XrmEmbeddingEnqueueJob($db, $state))->go());
		$this->assertSame('2026-05-10 12:00:00', $state->get(self::CHANGED_KEY));
		$this->assertTrue($state->has(self::LAST_RUN_KEY));
		$this->assertCount(8, $writes);
	}

	public function testDeleteJobsUseDefaultCollectionAndStoreTheirOwnIdentifiers(): void {
		$reads = [];
		$writes = [];
		$state = new StateStoreStub();
		$db = $this->database([], [
			['source_uuid' => 'deleted-1', 'last_seen_version' => 'version-1', 'last_seen_collection_key' => '  '],
			['source_uuid' => 'deleted-2', 'last_seen_version' => 'version-2', 'last_seen_collection_key' => ' archive ']
		], $reads, $writes, [31, 32]);

		$this->assertSame('Enqueue done - changed: 0, deletes: 2', (new XrmEmbeddingEnqueueJob($db, $state))->go());
		$deleteInserts = array_values(array_filter($writes, static fn(string $sql): bool => str_contains($sql, "'delete', 'pending'")));
		$this->assertCount(2, $deleteInserts);
		$this->assertStringContainsString("'default'", $deleteInserts[0]);
		$this->assertStringContainsString("'archive'", $deleteInserts[1]);
		$this->assertStringContainsString('delete_job_id = 31', $writes[4]);
		$this->assertStringContainsString('delete_job_id = 32', $writes[7]);
		$this->assertCount(4, $reads);
	}

	public function testSecondInvocationHonorsCheckpointWrittenByFirstInvocation(): void {
		$reads = [];
		$writes = [];
		$state = new StateStoreStub();
		$db = $this->database([], [], $reads, $writes);
		$job = new XrmEmbeddingEnqueueJob($db, $state);

		$this->assertSame('Enqueue done - changed: 0, deletes: 0', $job->go());
		$this->assertSame('Skip (min interval not reached)', $job->go());
		$this->assertCount(2, $reads);
		$this->assertCount(4, $writes);
	}

	private function database(array $changed, array $deleted, array &$reads, array &$writes, array $deleteIds = []): IDatabase {
		$db = $this->createStub(IDatabase::class);
		$db->method('connected')->willReturn(true);
		$db->method('escape')->willReturnCallback(static fn(string $value): string => addslashes($value));
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
		$index = 0;
		$db->method('singleQuery')->willReturnCallback(static function(string $sql) use (&$reads, &$index, $deleteIds): ?array {
			$reads[] = $sql;
			if (str_contains($sql, "job_type = 'delete'") && isset($deleteIds[$index])) {
				return ['job_id' => $deleteIds[$index++]];
			}
			return null;
		});
		return $db;
	}
}
