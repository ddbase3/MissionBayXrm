<?php declare(strict_types=1);

namespace MissionBayXrm\Job;

use Base3\Worker\Api\IJob;
use Base3\Database\Api\IDatabase;

/**
 * XrmEmbeddingEnqueueJob
 *
 * Worker A (enqueue):
 * - Scans the source table incrementally (checkpoint by changed timestamp)
 * - Writes work items into the embedding job queue
 * - Tracks last seen state per source UUID (seen table)
 * - Detects deletions by comparing seen vs current source (missing rows)
 *
 * IMPORTANT (as agreed):
 * - We must persist `collection_key` in BOTH:
 *   - base3_embedding_job (so Worker B knows where to upsert/delete)
 *   - base3_embedding_seen (so delete jobs can be created even if the source row is gone)
 *
 * This makes the queue system generic and safe for multi-collection setups.
 */
final class XrmEmbeddingEnqueueJob implements IJob {

	private const CHECKPOINT_NAME = 'sysentry';
	private const MIN_INTERVAL_SECONDS = 900; // 15 minutes
	private const CHANGED_BATCH = 5000;
	private const DELETE_BATCH = 2000;

	/**
	 * Fallback collection key if source type alias is missing.
	 * Keep stable and short.
	 */
	private const DEFAULT_COLLECTION_KEY = 'default';

	public function __construct(private readonly IDatabase $db) {}

	public static function getName(): string {
		return 'xrmembeddingenqueuejob';
	}

	public function isActive() {
		return true;
	}

	public function getPriority() {
		return 1;
	}

	public function go() {
		$this->db->connect();
		if (!$this->db->connected()) {
			return 'DB not connected';
		}

		$this->ensureTables();

		$checkpoint = $this->loadCheckpoint();
		if (!$this->shouldRun($checkpoint)) {
			return 'Skip (min interval not reached)';
		}

		$changed = $this->enqueueChanged(self::CHANGED_BATCH, $checkpoint['last_changed']);
		$deleted = $this->enqueueDeletes(self::DELETE_BATCH);

		$this->touchCheckpointRunAt();

		return 'Enqueue done - changed: ' . $changed . ', deletes: ' . $deleted;
	}

	/* ---------- Enqueue logic ---------- */

	private function enqueueChanged(int $limit, string $lastChanged): int {
		$rows = $this->queryAll(
			"SELECT
				e.uuid,
				e.etag,
				e.changed,
				t.alias AS type_alias
			FROM base3system_sysentry e
			JOIN base3system_systype t ON t.id = e.type_id
			WHERE e.changed > '" . $this->esc($lastChanged) . "'
			ORDER BY e.changed ASC
			LIMIT " . (int)$limit
		);

		if (!$rows) {
			return 0;
		}

		$maxChanged = $lastChanged;
		$processed = 0;

		foreach ($rows as $row) {
			$uuid = $row['uuid'];
			$etag = $row['etag'];
			$changed = $row['changed'];

			$collectionKey = $this->normalizeCollectionKey((string)($row['type_alias'] ?? ''));

			$this->upsertSeen($uuid, $etag, $changed, $collectionKey);
			$this->insertUpsertJob($uuid, $etag, $collectionKey);

			if ($changed > $maxChanged) {
				$maxChanged = $changed;
			}
			$processed++;
		}

		$this->updateCheckpointLastChanged($maxChanged);

		return $processed;
	}

	private function enqueueDeletes(int $limit): int {
		$rows = $this->queryAll(
			"SELECT
				s.source_uuid,
				s.last_seen_version,
				s.last_seen_collection_key
			FROM base3_embedding_seen s
			LEFT JOIN base3system_sysentry e ON e.uuid = s.source_uuid
			WHERE e.uuid IS NULL
				AND s.missing_since IS NULL
			LIMIT " . (int)$limit
		);

		if (!$rows) {
			return 0;
		}

		$processed = 0;

		foreach ($rows as $row) {
			$uuid = $row['source_uuid'];
			$lastSeenVersion = $row['last_seen_version'];
			$collectionKey = $this->normalizeCollectionKey((string)($row['last_seen_collection_key'] ?? ''));

			$this->markMissing($uuid);

			$jobId = $this->insertDeleteJobAndGetId($uuid, $lastSeenVersion, $collectionKey);
			if ($jobId !== null) {
				$this->setDeleteJobId($uuid, $jobId);
			}
			$processed++;
		}

		return $processed;
	}

	/* ---------- Seen / Job helpers ---------- */

	private function upsertSeen(string $uuid, string $etag, string $changed, string $collectionKey): void {
		$this->exec(
			"INSERT INTO base3_embedding_seen
				(source_uuid, last_seen_version, last_seen_changed, last_seen_at, last_seen_collection_key, missing_since, delete_job_id, deleted_at)
			VALUES
				('" . $this->escBin($uuid) . "', '" . $this->escBin($etag) . "', '" . $this->esc($changed) . "', NOW(), '" . $this->esc($collectionKey) . "', NULL, NULL, NULL)
			ON DUPLICATE KEY UPDATE
				last_seen_version = VALUES(last_seen_version),
				last_seen_changed = VALUES(last_seen_changed),
				last_seen_at = NOW(),
				last_seen_collection_key = VALUES(last_seen_collection_key),
				missing_since = NULL,
				delete_job_id = NULL,
				deleted_at = NULL"
		);
	}

	private function insertUpsertJob(string $uuid, string $etag, string $collectionKey): void {
		$this->exec(
			"INSERT IGNORE INTO base3_embedding_job
				(source_uuid, source_version, collection_key, job_type, state, priority, attempts, locked_until, claim_token, claimed_at, created_at, updated_at, error_message)
			VALUES
				('" . $this->escBin($uuid) . "', '" . $this->escBin($etag) . "', '" . $this->esc($collectionKey) . "', 'upsert', 'pending', 1, 0, NULL, NULL, NULL, NOW(), NOW(), NULL)"
		);
	}

	private function markMissing(string $uuid): void {
		$this->exec(
			"UPDATE base3_embedding_seen
			SET missing_since = NOW()
			WHERE source_uuid = '" . $this->escBin($uuid) . "'
				AND missing_since IS NULL"
		);
	}

	private function insertDeleteJobAndGetId(string $uuid, string $lastSeenVersion, string $collectionKey): ?int {
		$this->exec(
			"INSERT IGNORE INTO base3_embedding_job
				(source_uuid, source_version, collection_key, job_type, state, priority, attempts, locked_until, claim_token, claimed_at, created_at, updated_at, error_message)
			VALUES
				('" . $this->escBin($uuid) . "', '" . $this->escBin($lastSeenVersion) . "', '" . $this->esc($collectionKey) . "', 'delete', 'pending', 1, 0, NULL, NULL, NULL, NOW(), NOW(), NULL)"
		);

		$row = $this->queryOne(
			"SELECT job_id
			FROM base3_embedding_job
			WHERE source_uuid = '" . $this->escBin($uuid) . "'
				AND source_version = '" . $this->escBin($lastSeenVersion) . "'
				AND collection_key = '" . $this->esc($collectionKey) . "'
				AND job_type = 'delete'
			ORDER BY job_id DESC
			LIMIT 1"
		);

		return $row['job_id'] ?? null;
	}

	private function setDeleteJobId(string $uuid, int $jobId): void {
		$this->exec(
			"UPDATE base3_embedding_seen
			SET delete_job_id = " . (int)$jobId . "
			WHERE source_uuid = '" . $this->escBin($uuid) . "'"
		);
	}

	/* ---------- Checkpoint ---------- */

	private function loadCheckpoint(): array {
		$row = $this->queryOne(
			"SELECT last_changed, last_run_at
			FROM base3_embedding_checkpoint
			WHERE name = '" . self::CHECKPOINT_NAME . "'
			LIMIT 1"
		);

		return [
			'last_changed' => $row['last_changed'] ?? '1970-01-01 00:00:00',
			'last_run_at' => $row['last_run_at'] ?? '1970-01-01 00:00:00'
		];
	}

	private function shouldRun(array $checkpoint): bool {
		if ($checkpoint['last_run_at'] === '1970-01-01 00:00:00') {
			return true;
		}
		$lastRunAt = strtotime((string)$checkpoint['last_run_at']);
		return (time() - $lastRunAt) >= self::MIN_INTERVAL_SECONDS;
	}

	private function touchCheckpointRunAt(): void {
		$this->exec(
			"UPDATE base3_embedding_checkpoint
			SET last_run_at = NOW()
			WHERE name = '" . self::CHECKPOINT_NAME . "'"
		);
	}

	private function updateCheckpointLastChanged(string $lastChanged): void {
		$this->exec(
			"UPDATE base3_embedding_checkpoint
			SET last_changed = '" . $this->esc($lastChanged) . "'
			WHERE name = '" . self::CHECKPOINT_NAME . "'"
		);
	}

	/* ---------- Schema ---------- */

	private function ensureTables(): void {
		$this->exec($this->getJobTableSql());
		$this->exec($this->getSeenTableSql());
		$this->exec($this->getCheckpointTableSql());

		$this->exec(
			"INSERT IGNORE INTO base3_embedding_checkpoint
				(name, last_changed, last_run_at)
			VALUES
				('" . self::CHECKPOINT_NAME . "', '1970-01-01 00:00:00', '1970-01-01 00:00:00')"
		);
	}

	private function getJobTableSql(): string {
		return "CREATE TABLE IF NOT EXISTS base3_embedding_job (
			job_id BIGINT NOT NULL AUTO_INCREMENT,
			source_uuid VARBINARY(16) NOT NULL,
			source_version VARBINARY(16) NULL,
			collection_key VARCHAR(64) NOT NULL,
			job_type ENUM('upsert','delete') NOT NULL,
			state ENUM('pending','running','done','error','superseded') NOT NULL DEFAULT 'pending',
			priority TINYINT NOT NULL DEFAULT 1,
			attempts INT NOT NULL DEFAULT 0,
			locked_until DATETIME NULL,
			claim_token CHAR(36) NULL,
			claimed_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			error_message TEXT NULL,
			PRIMARY KEY (job_id),
			UNIQUE KEY uq_job (source_uuid, source_version, collection_key, job_type),
			KEY ix_claim (state, priority, locked_until, updated_at),
			KEY ix_claim_token (claim_token),
			KEY ix_source (source_uuid, job_type),
			KEY ix_collection (collection_key)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
	}

	private function getSeenTableSql(): string {
		return "CREATE TABLE IF NOT EXISTS base3_embedding_seen (
			source_uuid VARBINARY(16) NOT NULL,
			last_seen_version VARBINARY(16) NOT NULL,
			last_seen_changed DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			last_seen_collection_key VARCHAR(64) NOT NULL,
			missing_since DATETIME NULL,
			delete_job_id BIGINT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY (source_uuid),
			KEY ix_missing (missing_since),
			KEY ix_delete_job (delete_job_id),
			KEY ix_seen_collection (last_seen_collection_key)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
	}

	private function getCheckpointTableSql(): string {
		return "CREATE TABLE IF NOT EXISTS base3_embedding_checkpoint (
			name VARCHAR(64) NOT NULL,
			last_changed DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_run_at DATETIME NOT NULL,
			PRIMARY KEY (name)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
	}

	/* ---------- Helpers ---------- */

	private function normalizeCollectionKey(string $key): string {
		$key = trim($key);
		if ($key === '') {
			return self::DEFAULT_COLLECTION_KEY;
		}
		return $key;
	}

	/* ---------- DB helpers ---------- */

	private function exec(string $sql): void {
		$this->db->nonQuery($sql);
	}

	private function queryAll(string $sql): array {
		return $this->db->multiQuery($sql) ?: [];
	}

	private function queryOne(string $sql): ?array {
		return $this->db->singleQuery($sql);
	}

	private function esc(string $value): string {
		return (string)$this->db->escape($value);
	}

	private function escBin(string $bin): string {
		return (string)$this->db->escape($bin);
	}
}
