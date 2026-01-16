<?php declare(strict_types=1);

namespace MissionBayXrm\Resource;

use Base3\Database\Api\IDatabase;
use MissionBay\Api\IAgentContext;
use MissionBay\Api\IAgentContentExtractor;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Resource\AbstractAgentResource;

final class XrmEmbeddingQueueExtractorAgentResource extends AbstractAgentResource implements IAgentContentExtractor {

	private const DEFAULT_CLAIM_LIMIT = 5;
	private const LOCK_MINUTES = 10;
	private const MAX_ATTEMPTS = 5;

	private const PUBLIC_USER_ID = 1;
	private const PUBLIC_MODE = 'visitor';

	private int $claimLimit = self::DEFAULT_CLAIM_LIMIT;

	public function __construct(
		private readonly IDatabase $db,
		private readonly IAgentConfigValueResolver $resolver,
		?string $id = null
	) {
		parent::__construct($id);
	}

	public static function getName(): string {
		return 'xrmembeddingqueueextractoragentresource';
	}

	public function getDescription(): string {
		return 'Claims embedding jobs from base3_embedding_job and returns AgentContentItem work units.';
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$value = $this->resolver->resolveValue($config['claim_limit'] ?? self::DEFAULT_CLAIM_LIMIT);

		$limit = (int)$value;
		if ($limit <= 0) {
			$limit = self::DEFAULT_CLAIM_LIMIT;
		}

		$this->claimLimit = $limit;
	}

	public function extract(IAgentContext $context): array {
		$this->db->connect();
		if (!$this->db->connected()) {
			return [];
		}

		$ids = $this->claimJobIds($this->claimLimit);
		if (!$ids) {
			return [];
		}

		$jobs = $this->loadClaimedJobs($ids);
		if (!$jobs) {
			return [];
		}

		$out = [];

		foreach ($jobs as $job) {
			$jobType = strtolower((string)($job['job_type'] ?? ''));
			if ($jobType !== 'upsert' && $jobType !== 'delete') {
				$this->failFromJobRow($job, "Extractor: unsupported job_type '$jobType'", false);
				continue;
			}

			$uuidHex = strtoupper((string)($job['source_uuid_hex'] ?? ''));
			$verHex = strtoupper((string)($job['source_version_hex'] ?? ''));
			$collectionKey = trim((string)($job['collection_key'] ?? ''));

			if ($uuidHex === '' || $collectionKey === '') {
				$this->failFromJobRow($job, 'Extractor: missing source_uuid or collection_key', false);
				continue;
			}

			if ($jobType === 'upsert' && $verHex !== '') {
				if ($this->isSupersededBySeen($uuidHex, $verHex, $collectionKey)) {
					$this->markSuperseded((int)$job['job_id']);
					continue;
				}
			}

			$item = $this->buildItemFromJob($job);
			if ($item === null) {
				$this->failFromJobRow($job, 'Extractor: missing payload / sysentry not found', true);
				continue;
			}

			$out[] = $item;
		}

		return $out;
	}

	public function ack(AgentContentItem $item, array $result = []): void {
		$this->db->connect();
		if (!$this->db->connected()) {
			return;
		}

		$jobId = (int)$item->id;
		if ($jobId <= 0) {
			return;
		}

		$this->markDone($jobId);

		if ($item->isDelete()) {
			$uuidHex = strtoupper(trim((string)($item->metadata['content_uuid'] ?? '')));
			$collectionKey = trim($item->collectionKey);

			if ($uuidHex !== '' && $collectionKey !== '') {
				$this->markSeenDeletedAt($uuidHex, $collectionKey);
			}
		}
	}

	public function fail(AgentContentItem $item, string $errorMessage, bool $retryHint = true): void {
		$this->db->connect();
		if (!$this->db->connected()) {
			return;
		}

		$jobId = (int)$item->id;
		if ($jobId <= 0) {
			return;
		}

		$attempts = $this->loadAttempts($jobId);
		$this->markFailed($jobId, $attempts, $errorMessage, $retryHint);
	}

	// ---------------------------------------------------------
	// Claiming
	// ---------------------------------------------------------

	private function claimJobIds(int $limit): array {
		$rows = $this->queryAll(
			"SELECT job_id
			FROM base3_embedding_job
			WHERE state = 'pending'
				AND (locked_until IS NULL OR locked_until < NOW())
			ORDER BY priority DESC, job_id ASC
			LIMIT " . (int)$limit
		);

		if (!$rows) {
			return [];
		}

		$ids = [];
		foreach ($rows as $r) {
			$ids[] = (int)$r['job_id'];
		}

		if (!$ids) {
			return [];
		}

		$idList = implode(',', array_map('intval', $ids));

		$this->exec(
			"UPDATE base3_embedding_job
			SET state = 'running',
				locked_until = DATE_ADD(NOW(), INTERVAL " . (int)self::LOCK_MINUTES . " MINUTE),
				attempts = attempts + 1,
				updated_at = NOW()
			WHERE state = 'pending'
				AND job_id IN (" . $idList . ")"
		);

		return $ids;
	}

	private function loadClaimedJobs(array $ids): array {
		$idList = implode(',', array_map('intval', $ids));

		return $this->queryAll(
			"SELECT
				job_id,
				job_type,
				attempts,
				collection_key,
				HEX(source_uuid) AS source_uuid_hex,
				HEX(source_version) AS source_version_hex
			FROM base3_embedding_job
			WHERE job_id IN (" . $idList . ")
				AND state = 'running'"
		);
	}

	// ---------------------------------------------------------
	// Superseded (collection-aware)
	// ---------------------------------------------------------

	private function isSupersededBySeen(string $uuidHex, string $versionHex, string $collectionKey): bool {
		$uuidHex = strtoupper(trim($uuidHex));
		$versionHex = strtoupper(trim($versionHex));
		$collectionKey = trim($collectionKey);

		if ($uuidHex === '' || $versionHex === '' || $collectionKey === '') {
			return false;
		}

		$row = $this->queryOne(
			"SELECT
				HEX(last_seen_version) AS last_seen_version_hex,
				last_seen_collection_key
			FROM base3_embedding_seen
			WHERE source_uuid = UNHEX('" . $this->esc($uuidHex) . "')
			LIMIT 1"
		);

		$lastVer = strtoupper((string)($row['last_seen_version_hex'] ?? ''));
		$lastCol = (string)($row['last_seen_collection_key'] ?? '');

		if ($lastVer === '') {
			return false;
		}

		if ($lastCol !== $collectionKey) {
			return false;
		}

		return $lastVer !== $versionHex;
	}

	private function markSuperseded(int $jobId): void {
		$this->exec(
			"UPDATE base3_embedding_job
			SET state = 'superseded',
				locked_until = NULL,
				updated_at = NOW()
			WHERE job_id = " . (int)$jobId
		);
	}

	// ---------------------------------------------------------
	// Item building (AgentContentItem-conform)
	// ---------------------------------------------------------

	private function buildItemFromJob(array $job): ?AgentContentItem {
		$jobId = (int)($job['job_id'] ?? 0);
		if ($jobId <= 0) {
			return null;
		}

		$jobType = strtolower((string)($job['job_type'] ?? ''));
		$collectionKey = trim((string)($job['collection_key'] ?? ''));
		$uuidHex = strtoupper((string)($job['source_uuid_hex'] ?? ''));
		$verHex = strtoupper((string)($job['source_version_hex'] ?? ''));

		if ($uuidHex === '' || $collectionKey === '') {
			return null;
		}

		$hash = hash('sha256', $collectionKey . ':' . $uuidHex . ':' . $verHex);

		$domainMeta = [
			'content_uuid' => $uuidHex,
			'content_version' => $verHex !== '' ? $verHex : null
		];

		if ($jobType === 'delete') {
			return new AgentContentItem(
				action: 'delete',
				collectionKey: $collectionKey,
				id: (string)$jobId,
				hash: $hash,
				contentType: 'application/x-embedding-job-delete',
				content: '',
				isBinary: false,
				size: 0,
				metadata: $domainMeta
			);
		}

		$entry = $this->loadSysentryWithType($uuidHex);
		if (!$entry) {
			return null;
		}

		$typeTable = (string)($entry['dbtable'] ?? '');
		$payloadId = (int)($entry['id'] ?? 0);

		if ($typeTable === '' || $payloadId <= 0) {
			return null;
		}

		$payload = $this->loadPayloadRow($typeTable, $payloadId);
		if ($payload === null) {
			return null;
		}

		$alias = (string)($entry['alias'] ?? '');
		if ($alias !== '') {
			$domainMeta['type_alias'] = $alias;
		}

		$archive = (int)($entry['archive'] ?? 0);
		$domainMeta['archive'] = ($archive === 1) ? 1 : 0;

		$entryId = (int)($entry['id'] ?? 0);
		if ($entryId > 0) {
			$domainMeta['public'] = $this->isEntryPublic($entryId) ? 1 : 0;

			// tags + ref_uuids
			$tags = $this->loadTagsByEntryId($entryId);
			if (!empty($tags)) {
				$domainMeta['tags'] = $tags;
			}

			$refUuids = $this->loadRefUuidsByEntryId($entryId);
			if (!empty($refUuids)) {
				$domainMeta['ref_uuids'] = $refUuids;
			}

			// NEW: name (sysname) -> domain metadata
			// Query: SELECT `name` FROM `base3system_sysname` WHERE entry_id=2 ORDER BY `lang_id` DESC LIMIT 1;
			$name = $this->loadNameByEntryId($entryId);
			if ($name !== null && $name !== '') {
				$domainMeta['name'] = $name;
			}
		} else {
			$domainMeta['public'] = 0;
		}

		$content = [
			'sysentry' => [
				'id' => (int)$entry['id'],
				'uuid' => $uuidHex,
				'type_id' => (int)$entry['type_id'],
				'changed' => (string)$entry['changed'],
				'created' => (string)$entry['created'],
				'etag' => strtoupper((string)($entry['etag_hex'] ?? $verHex))
			],
			'type' => [
				'id' => (int)$entry['type_id'],
				'alias' => $alias,
				'table' => $typeTable
			],
			'payload' => $payload
		];

		$json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$size = is_string($json) ? strlen($json) : 0;

		return new AgentContentItem(
			action: 'upsert',
			collectionKey: $collectionKey,
			id: (string)$jobId,
			hash: $hash,
			contentType: 'application/x-xrm-sysentry-json',
			content: $content,
			isBinary: false,
			size: $size,
			metadata: $domainMeta
		);
	}

	private function loadSysentryWithType(string $uuidHex): ?array {
		$uuidHex = strtoupper(trim($uuidHex));
		if ($uuidHex === '') {
			return null;
		}

		return $this->queryOne(
			"SELECT
				e.id,
				HEX(e.uuid) AS uuid_hex,
				e.type_id,
				e.archive,
				HEX(e.etag) AS etag_hex,
				e.created,
				e.changed,
				t.alias,
				t.dbtable
			FROM base3system_sysentry e
			JOIN base3system_systype t ON t.id = e.type_id
			WHERE e.uuid = UNHEX('" . $this->esc($uuidHex) . "')
			LIMIT 1"
		);
	}

	private function loadPayloadRow(string $table, int $id): ?array {
		if (!$this->isSafeTableName($table)) {
			return null;
		}

		return $this->queryOne(
			"SELECT *
			FROM " . $table . "
			WHERE id = " . (int)$id . "
			LIMIT 1"
		);
	}

	private function isSafeTableName(string $table): bool {
		return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $table);
	}

	// ---------------------------------------------------------
	// NEW: tags + refs + public + name
	// ---------------------------------------------------------

	/**
	 * Public rule:
	 * - entry is public if base3system_sysuseraccess has (entry_id=<id>, user_id=1, mode='visitor')
	 */
	private function isEntryPublic(int $entryId): bool {
		if ($entryId <= 0) {
			return false;
		}

		$row = $this->queryOne(
			"SELECT 1 AS ok
			FROM base3system_sysuseraccess
			WHERE entry_id = " . (int)$entryId . "
				AND user_id = " . (int)self::PUBLIC_USER_ID . "
				AND mode = '" . $this->esc(self::PUBLIC_MODE) . "'
			LIMIT 1"
		);

		return !empty($row);
	}

	/**
	 * Loads best matching name from base3system_sysname.
	 * Prefers higher lang_id (same as your example query).
	 */
	private function loadNameByEntryId(int $entryId): ?string {
		if ($entryId <= 0) {
			return null;
		}

		$row = $this->queryOne(
			"SELECT name
			FROM base3system_sysname
			WHERE entry_id = " . (int)$entryId . "
			ORDER BY lang_id DESC
			LIMIT 1"
		);

		$name = trim((string)($row['name'] ?? ''));
		return $name !== '' ? $name : null;
	}

	/**
	 * @return array<int,string>
	 */
	private function loadTagsByEntryId(int $entryId): array {
		if ($entryId <= 0) {
			return [];
		}

		$rows = $this->queryAll(
			"SELECT tag
			FROM base3system_systag
			WHERE entry_id = " . (int)$entryId . "
			ORDER BY tag ASC"
		);

		$out = [];
		$seen = [];

		foreach ($rows as $r) {
			$tag = trim((string)($r['tag'] ?? ''));
			if ($tag === '') {
				continue;
			}

			$tag = strtolower($tag);

			if (isset($seen[$tag])) {
				continue;
			}

			$seen[$tag] = true;
			$out[] = $tag;
		}

		return $out;
	}

	/**
	 * Loads connected entry UUIDs (peers) via sysallocview.
	 *
	 * @return array<int,string> UUID hex (upper, 32 chars)
	 */
	private function loadRefUuidsByEntryId(int $entryId): array {
		if ($entryId <= 0) {
			return [];
		}

		$rows = $this->queryAll(
			"SELECT DISTINCT HEX(e.uuid) AS uuid_hex
			FROM base3system_sysallocview v
			JOIN base3system_sysentry e ON e.id = v.peer_id
			WHERE v.entry_id = " . (int)$entryId . "
			ORDER BY e.id ASC"
		);

		$out = [];
		$seen = [];

		foreach ($rows as $r) {
			$hex = strtoupper(trim((string)($r['uuid_hex'] ?? '')));
			if ($hex === '') {
				continue;
			}

			$hex = preg_replace('/[^0-9A-F]/', '', $hex) ?? '';
			if ($hex === '' || strlen($hex) !== 32) {
				continue;
			}

			if (isset($seen[$hex])) {
				continue;
			}

			$seen[$hex] = true;
			$out[] = $hex;
		}

		return $out;
	}

	// ---------------------------------------------------------
	// Job state
	// ---------------------------------------------------------

	private function loadAttempts(int $jobId): int {
		$row = $this->queryOne(
			"SELECT attempts
			FROM base3_embedding_job
			WHERE job_id = " . (int)$jobId . "
			LIMIT 1"
		);

		return (int)($row['attempts'] ?? 0);
	}

	private function markDone(int $jobId): void {
		$this->exec(
			"UPDATE base3_embedding_job
			SET state = 'done',
				locked_until = NULL,
				updated_at = NOW(),
				error_message = NULL
			WHERE job_id = " . (int)$jobId
		);
	}

	private function markFailed(int $jobId, int $attempts, string $msg, bool $retryHint): void {
		$msg = mb_substr($msg, 0, 4000);

		$attempts = max(0, $attempts);
		$final = (!$retryHint) || ($attempts >= self::MAX_ATTEMPTS);

		if ($final) {
			$this->exec(
				"UPDATE base3_embedding_job
				SET state = 'error',
					locked_until = NULL,
					updated_at = NOW(),
					error_message = '" . $this->esc($msg) . "'
				WHERE job_id = " . (int)$jobId
			);
			return;
		}

		$this->exec(
			"UPDATE base3_embedding_job
			SET state = 'pending',
				locked_until = NULL,
				updated_at = NOW(),
				error_message = '" . $this->esc($msg) . "'
			WHERE job_id = " . (int)$jobId
		);
	}

	private function markSeenDeletedAt(string $uuidHex, string $collectionKey): void {
		$uuidHex = strtoupper(trim($uuidHex));
		$collectionKey = trim($collectionKey);

		if ($uuidHex === '' || $collectionKey === '') {
			return;
		}

		$this->exec(
			"UPDATE base3_embedding_seen
			SET deleted_at = NOW()
			WHERE source_uuid = UNHEX('" . $this->esc($uuidHex) . "')
				AND last_seen_collection_key = '" . $this->esc($collectionKey) . "'"
		);
	}

	private function failFromJobRow(array $job, string $msg, bool $retryHint): void {
		$jobId = (int)($job['job_id'] ?? 0);
		if ($jobId <= 0) {
			return;
		}

		$attempts = (int)($job['attempts'] ?? 0);
		$this->markFailed($jobId, $attempts, $msg, $retryHint);
	}

	// ---------------------------------------------------------
	// DB helpers
	// ---------------------------------------------------------

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
}
