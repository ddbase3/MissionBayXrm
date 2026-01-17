<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Job;

use Base3\Database\Api\IDatabase;
use MissionBayXrm\Job\XrmEmbeddingEnqueueJob;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MissionBayXrm\Job\XrmEmbeddingEnqueueJob
 */
final class XrmEmbeddingEnqueueJobTest extends TestCase {

	private function makeDbMock(): IDatabase {
		return $this->createMock(IDatabase::class);
	}

	/**
	 * Helper for IDatabase::multiQuery() which is declared by-reference.
	 * We must return an array by reference to avoid PHP notices / type issues.
	 */
	private function willReturnMultiQueryMap(IDatabase $db, array $map): void {
		$db->method('multiQuery')->willReturnCallback(function (string $sql) use (&$map): array {
			foreach ($map as $entry) {
				$needle = $entry['needle'];
				$rows = &$entry['rows']; // reference!
				if (stripos($sql, $needle) !== false) {
					return $rows;
				}
			}
			$empty = [];
			return $empty;
		});
	}

	public function testGoReturnsDbNotConnectedWhenConnectFails(): void {
		$db = $this->makeDbMock();

		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(false);

		$job = new XrmEmbeddingEnqueueJob($db);

		$this->assertSame('DB not connected', $job->go());
	}

	public function testGoSkipsWhenMinIntervalNotReached(): void {
		$db = $this->makeDbMock();

		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		// escape passthrough
		$db->method('escape')->willReturnCallback(fn(string $s): string => $s);

		// ensureTables -> DDLs
		$db->expects($this->atLeast(1))->method('nonQuery');

		// loadCheckpoint: last_run_at = now => should skip
		$db->expects($this->once())
			->method('singleQuery')
			->with($this->stringContains('FROM base3_embedding_checkpoint'))
			->willReturn([
				'last_changed' => '2026-01-01 00:00:00',
				'last_run_at' => date('Y-m-d H:i:s'),
			]);

		$job = new XrmEmbeddingEnqueueJob($db);

		$this->assertSame('Skip (min interval not reached)', $job->go());
	}

	public function testGoEnqueuesChangedAndDeletesAndUpdatesCheckpoint(): void {
		$db = $this->makeDbMock();

		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		$db->method('escape')->willReturnCallback(fn(string $s): string => $s);

		// singleQuery: checkpoint + delete job id lookup
		$db->method('singleQuery')->willReturnCallback(function (string $sql): ?array {
			if (stripos($sql, 'FROM base3_embedding_checkpoint') !== false) {
				return [
					'last_changed' => '1970-01-01 00:00:00',
					'last_run_at' => '1970-01-01 00:00:00',
				];
			}

			if (stripos($sql, 'FROM base3_embedding_job') !== false && stripos($sql, "job_type = 'delete'") !== false) {
				return ['job_id' => 42];
			}

			return null;
		});

		$changedRows = [
			[
				'uuid' => "bin-uuid-1",
				'etag' => "bin-etag-1",
				'changed' => '2026-01-02 10:00:00',
				'type_alias' => 'xrm',
			],
			[
				'uuid' => "bin-uuid-2",
				'etag' => "bin-etag-2",
				'changed' => '2026-01-02 11:00:00',
				'type_alias' => '',
			],
		];

		$deleteRows = [
			[
				'source_uuid' => 'bin-uuid-del-1',
				'last_seen_version' => 'bin-ver-del-1',
				'last_seen_collection_key' => 'xrm',
			],
		];

		$this->willReturnMultiQueryMap($db, [
			['needle' => 'FROM base3system_sysentry', 'rows' => $changedRows],
			['needle' => 'FROM base3_embedding_seen', 'rows' => $deleteRows],
		]);

		// We just need: some writes happened, and the final message is correct.
		$db->expects($this->atLeastOnce())->method('nonQuery');

		$job = new XrmEmbeddingEnqueueJob($db);

		$msg = $job->go();

		$this->assertStringContainsString('Enqueue done - changed: 2, deletes: 1', $msg);
	}

	public function testNormalizeCollectionKeyFallsBackToDefaultWhenTypeAliasMissing(): void {
		$db = $this->makeDbMock();

		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		$db->method('escape')->willReturnCallback(fn(string $s): string => $s);

		// allow run
		$db->method('singleQuery')->willReturn([
			'last_changed' => '1970-01-01 00:00:00',
			'last_run_at' => '1970-01-01 00:00:00',
		]);

		$changedRows = [
			[
				'uuid' => "bin-uuid-1",
				'etag' => "bin-etag-1",
				'changed' => '2026-01-02 10:00:00',
				'type_alias' => '', // triggers default
			],
		];
		$deleteRows = [];

		$this->willReturnMultiQueryMap($db, [
			['needle' => 'FROM base3system_sysentry', 'rows' => $changedRows],
			['needle' => 'FROM base3_embedding_seen', 'rows' => $deleteRows],
		]);

		// nonQuery will be called for DDL + inserts; we only assert that among them,
		// at least one upsert-job insert contains "'default'" as collection_key.
		$db->expects($this->atLeastOnce())
			->method('nonQuery')
			->with($this->callback(function (string $sql): bool {
				$sqlLower = strtolower($sql);

				// allow DDL and checkpoint bootstrap etc.
				if (str_contains($sqlLower, 'create table if not exists')) {
					return true;
				}
				if (str_contains($sqlLower, 'insert ignore into base3_embedding_checkpoint')) {
					return true;
				}
				if (str_contains($sqlLower, 'insert into base3_embedding_seen')) {
					return true;
				}

				// the one we actually care about:
				// insert upsert job with collection_key = 'default'
				if (str_contains($sqlLower, 'insert ignore into base3_embedding_job')
					&& str_contains($sqlLower, "'upsert'")
					&& str_contains($sqlLower, "'default'")
				) {
					return true;
				}

				// also allow checkpoint updates
				if (str_contains($sqlLower, 'update base3_embedding_checkpoint')) {
					return true;
				}

				return false;
			}));

		$job = new XrmEmbeddingEnqueueJob($db);
		$job->go();
	}

	public function testEnqueueDeletesUsesLastSeenCollectionKeyAndSetsDeleteJobId(): void {
		$db = $this->makeDbMock();

		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		$db->method('escape')->willReturnCallback(fn(string $s): string => $s);

		// checkpoint + delete job id lookup
		$db->method('singleQuery')->willReturnCallback(function (string $sql): ?array {
			if (stripos($sql, 'FROM base3_embedding_checkpoint') !== false) {
				return [
					'last_changed' => '1970-01-01 00:00:00',
					'last_run_at' => '1970-01-01 00:00:00',
				];
			}

			if (stripos($sql, 'FROM base3_embedding_job') !== false && stripos($sql, "job_type = 'delete'") !== false) {
				return ['job_id' => 99];
			}

			return null;
		});

		$changedRows = [];
		$deleteRows = [
			[
				'source_uuid' => 'bin-uuid-del-1',
				'last_seen_version' => 'bin-ver-del-1',
				'last_seen_collection_key' => 'xrm',
			],
		];

		$this->willReturnMultiQueryMap($db, [
			['needle' => 'FROM base3system_sysentry', 'rows' => $changedRows],
			['needle' => 'FROM base3_embedding_seen', 'rows' => $deleteRows],
		]);

		$db->expects($this->atLeastOnce())
			->method('nonQuery')
			->with($this->callback(function (string $sql): bool {
				$sqlLower = strtolower($sql);

				// allow ensureTables noise
				if (str_contains($sqlLower, 'create table if not exists')) {
					return true;
				}
				if (str_contains($sqlLower, 'insert ignore into base3_embedding_checkpoint')) {
					return true;
				}
				if (str_contains($sqlLower, 'update base3_embedding_checkpoint')) {
					return true;
				}

				// required signals:
				if (str_contains($sqlLower, 'update base3_embedding_seen') && str_contains($sqlLower, 'set missing_since')) {
					return true;
				}
				if (str_contains($sqlLower, 'insert ignore into base3_embedding_job') && str_contains($sqlLower, "'delete'") && str_contains($sqlLower, "'xrm'")) {
					return true;
				}
				if (str_contains($sqlLower, 'update base3_embedding_seen') && str_contains($sqlLower, 'set delete_job_id = 99')) {
					return true;
				}

				return false;
			}));

		$job = new XrmEmbeddingEnqueueJob($db);
		$msg = $job->go();

		$this->assertStringContainsString('changed: 0, deletes: 1', $msg);
	}
}
