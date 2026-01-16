<?php declare(strict_types=1);

namespace MissionBayXrm\Agent;

use MissionBay\Api\IAgentRagPayloadNormalizer;
use MissionBay\Dto\AgentEmbeddingChunk;

/**
 * XrmAgentRagPayloadNormalizer
 *
 * XRM normalizer for Qdrant payloads.
 *
 * Adds filterable fields:
 * - tags: array<string>
 * - ref_uuids: array<string> (normalized to upper hex, like content_uuid)
 * - num_chunks: int (same value for all chunks of one content item)
 * - archive: int (0|1) to filter archived entries
 * - public: int (0|1) to filter public entries
 *
 * Agreements implemented:
 * - 'name' is a top-level payload field (not in meta)
 * - 'name' is FULLTEXT indexed => schema type 'text'
 * - prefix search is planned; tokenizer config will be added at index creation time later
 * - no fallback logic for name (upstream decides)
 * - unknown domain metadata is preserved as payload.meta
 *
 * Index policy (Option A):
 * - Schema declares exactly which payload fields should get a Qdrant payload index via 'index' => true.
 * - Vector store ensures only those indexes (no filter-driven auto-indexing).
 * - payload.meta is intentionally NOT indexed.
 */
final class XrmAgentRagPayloadNormalizer implements IAgentRagPayloadNormalizer {

	/** @var string canonical logical collection key for XRM */
	private const CANONICAL_COLLECTION_KEY = 'xrm';

	/** @var string physical backend collection name in Qdrant */
	private const BACKEND_COLLECTION = 'xrm_content_v1';

	/** @var int embedding vector size */
	private const VECTOR_SIZE = 1536;

	/** @var string Qdrant distance */
	private const DISTANCE = 'Cosine';

	/**
	 * IMPORTANT:
	 * - Default OFF: don't spam CLI output unless explicitly enabled.
	 * - Can be flipped via setDebug(true) from your CLI runner/bootstrap if you want.
	 */
	private bool $debug = false;

	public function setDebug(bool $debug): void {
		$this->debug = $debug;
	}

	public function getCollectionKeys(): array {
		return [self::CANONICAL_COLLECTION_KEY];
	}

	public function getBackendCollectionName(string $collectionKey): string {
		$this->mapToCanonicalCollectionKey($collectionKey);
		return self::BACKEND_COLLECTION;
	}

	public function getVectorSize(string $collectionKey): int {
		$this->mapToCanonicalCollectionKey($collectionKey);
		return self::VECTOR_SIZE;
	}

	public function getDistance(string $collectionKey): string {
		$this->mapToCanonicalCollectionKey($collectionKey);
		return self::DISTANCE;
	}

	/**
	 * Schema format:
	 * - ['type' => 'keyword'|'integer'|'float'|'bool'|'text'|'uuid', 'index' => bool]
	 *
	 * Notes:
	 * - Only fields with index=true are intended to be indexed in Qdrant.
	 * - Indexes are required for scroll/delete-by-filter on those fields (Qdrant constraint).
	 * - payload.meta is not part of the schema on purpose (no indexing).
	 */
	public function getSchema(string $collectionKey): array {
		$this->mapToCanonicalCollectionKey($collectionKey);

		return [
			// always present (core lifecycle / identity) - indexed
			'text' => ['type' => 'text', 'index' => false], // stored, but we do not filter by it here
			'hash' => ['type' => 'keyword', 'index' => true],
			'collection_key' => ['type' => 'keyword', 'index' => true],
			'content_uuid' => ['type' => 'keyword', 'index' => true],
			'chunktoken' => ['type' => 'keyword', 'index' => true],
			'chunk_index' => ['type' => 'integer', 'index' => true],

			// optional fields (only index those we actually plan to filter on)
			'source_id' => ['type' => 'keyword', 'index' => false],

			// AGREEMENT: name is fulltext indexed
			// (prefix tokenizer config is applied later at index creation time)
			'name' => ['type' => 'text', 'index' => true],

			'type_alias' => ['type' => 'keyword', 'index' => true],

			// keep available but not indexed for now (can be enabled later by setting index=>true)
			'content_id' => ['type' => 'keyword', 'index' => false],
			'url' => ['type' => 'keyword', 'index' => false],
			'filename' => ['type' => 'keyword', 'index' => false],
			'lang' => ['type' => 'keyword', 'index' => false],
			'created_at' => ['type' => 'keyword', 'index' => false],
			'updated_at' => ['type' => 'keyword', 'index' => false],

			// convenience flags - indexed (common filters)
			'num_chunks' => ['type' => 'integer', 'index' => false],
			'archive' => ['type' => 'integer', 'index' => true],
			'public' => ['type' => 'integer', 'index' => true],

			// filterable arrays - indexed
			'tags' => ['type' => 'keyword', 'index' => true],
			'ref_uuids' => ['type' => 'keyword', 'index' => true],
		];
	}

	public function validate(AgentEmbeddingChunk $chunk): void {
		$incomingKey = trim((string)$chunk->collectionKey);
		if ($incomingKey === '') {
			throw new \RuntimeException('AgentEmbeddingChunk.collectionKey is required.');
		}

		$canonical = $this->mapToCanonicalCollectionKey($incomingKey);

		if (!is_int($chunk->chunkIndex) || $chunk->chunkIndex < 0) {
			throw new \RuntimeException('AgentEmbeddingChunk.chunkIndex must be >= 0.');
		}

		$text = trim((string)$chunk->text);
		if ($text === '') {
			throw new \RuntimeException('AgentEmbeddingChunk.text must be non-empty for XRM.');
		}

		$hash = trim((string)$chunk->hash);
		if ($hash === '') {
			throw new \RuntimeException('AgentEmbeddingChunk.hash must be non-empty for XRM upsert.');
		}

		if (!is_array($chunk->metadata)) {
			throw new \RuntimeException('AgentEmbeddingChunk.metadata must be an array.');
		}

		$contentUuid = $chunk->metadata['content_uuid'] ?? null;
		if (!is_string($contentUuid) || trim($contentUuid) === '') {
			throw new \RuntimeException("Missing required metadata field 'content_uuid' for XRM.");
		}

		// Strict optional arrays
		if (array_key_exists('tags', $chunk->metadata) && !is_array($chunk->metadata['tags'])) {
			throw new \RuntimeException("metadata field 'tags' must be an array if provided.");
		}
		if (array_key_exists('ref_uuids', $chunk->metadata) && !is_array($chunk->metadata['ref_uuids'])) {
			throw new \RuntimeException("metadata field 'ref_uuids' must be an array if provided.");
		}

		// Strict optional int
		if (array_key_exists('num_chunks', $chunk->metadata)) {
			$n = $chunk->metadata['num_chunks'];

			if (!(is_int($n) || (is_string($n) && ctype_digit($n)))) {
				throw new \RuntimeException("metadata field 'num_chunks' must be an integer if provided.");
			}

			$n = (int)$n;
			if ($n <= 0) {
				throw new \RuntimeException("metadata field 'num_chunks' must be > 0 if provided.");
			}
		}

		// Strict optional boolean-int (0|1)
		$this->assertBoolIntMeta($chunk->metadata, 'archive');
		$this->assertBoolIntMeta($chunk->metadata, 'public');

		// Canonicalize key for downstream consistency (store/delete/exists).
		$chunk->collectionKey = $canonical;
	}

	public function buildPayload(AgentEmbeddingChunk $chunk): array {
		$this->validate($chunk);

		$meta = is_array($chunk->metadata) ? $chunk->metadata : [];

		$payload = [
			'text' => trim((string)$chunk->text),
			'hash' => trim((string)$chunk->hash),
			'collection_key' => self::CANONICAL_COLLECTION_KEY,
			'content_uuid' => $this->asUpperHex($meta['content_uuid'] ?? null),
			'chunktoken' => $this->buildChunkToken((string)$chunk->hash, (int)$chunk->chunkIndex),
			'chunk_index' => (int)$chunk->chunkIndex,
		];

		// Optional scalar fields
		$this->addIfString($payload, 'source_id', $meta['source_id'] ?? null);

		// AGREEMENT: no fallback. If name is empty, it is omitted.
		$this->addIfString($payload, 'name', $meta['name'] ?? null);

		$this->addIfString($payload, 'type_alias', $meta['type_alias'] ?? null);
		$this->addIfString($payload, 'content_id', $meta['content_id'] ?? null);
		$this->addIfString($payload, 'url', $meta['url'] ?? null);
		$this->addIfString($payload, 'filename', $meta['filename'] ?? null);
		$this->addIfString($payload, 'lang', $meta['lang'] ?? null);
		$this->addIfString($payload, 'created_at', $meta['created_at'] ?? null);
		$this->addIfString($payload, 'updated_at', $meta['updated_at'] ?? null);

		// Optional ints
		$this->addIfInt($payload, 'num_chunks', $meta['num_chunks'] ?? null);
		$this->addIfBoolInt($payload, 'archive', $meta['archive'] ?? null);
		$this->addIfBoolInt($payload, 'public', $meta['public'] ?? null);

		// Optional arrays (filterable)
		$tags = $this->normalizeStringArray($meta['tags'] ?? null, false, true);
		if (!empty($tags)) {
			$payload['tags'] = $tags;
		}

		$refUuids = $this->normalizeStringArray($meta['ref_uuids'] ?? null, true, false);
		if (!empty($refUuids)) {
			$payload['ref_uuids'] = $refUuids;
		}

		// Unknown domain metadata is preserved as meta (but operational keys are filtered out)
		$metaOut = $this->collectMeta($meta, [
			'content_uuid',
			'hash',
			'chunk_index',
			'chunktoken',
			'collection_key',

			'source_id',
			'name',
			'type_alias',
			'content_id',
			'url',
			'filename',
			'lang',
			'created_at',
			'updated_at',

			'num_chunks',
			'archive',
			'public',

			'tags',
			'ref_uuids',
		]);

		if (!empty($metaOut)) {
			$payload['meta'] = $metaOut;
		}

		return $payload;
	}

	// ---------------------------------------------------------
	// Routing
	// ---------------------------------------------------------

	private function mapToCanonicalCollectionKey(string $incomingKey): string {
		$incomingKey = strtolower(trim($incomingKey));
		if ($incomingKey === '') {
			throw new \InvalidArgumentException("collectionKey must be non-empty for XRM normalizer.");
		}

		if ($incomingKey !== self::CANONICAL_COLLECTION_KEY) {
			$this->debugLog("Mapping incoming collectionKey '{$incomingKey}' -> '" . self::CANONICAL_COLLECTION_KEY . "'");
		}

		return self::CANONICAL_COLLECTION_KEY;
	}

	private function debugLog(string $msg): void {
		if (!$this->debug) {
			return;
		}
		echo "[AgentRagPayloadNormalizer] {$msg}\n";
	}

	// ---------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------

	private function buildChunkToken(string $hash, int $chunkIndex): string {
		$hash = trim($hash);
		if ($hash === '') {
			throw new \RuntimeException('Cannot build chunktoken: hash is empty.');
		}
		return $chunkIndex > 0 ? ($hash . '-' . $chunkIndex) : $hash;
	}

	private function asString(mixed $v): ?string {
		if ($v === null) return null;
		if (is_string($v)) return $v;
		if (is_numeric($v) || is_bool($v)) return (string)$v;
		return null;
	}

	private function asUpperHex(mixed $v): string {
		$s = $this->asString($v);
		if ($s === null) {
			return '';
		}

		$s = trim($s);
		if ($s === '') {
			return '';
		}

		$s = preg_replace('/[^0-9a-fA-F]/', '', $s);
		if ($s === null || $s === '') {
			return '';
		}

		return strtoupper($s);
	}

	private function addIfString(array &$payload, string $key, mixed $value): void {
		$s = $this->asString($value);
		if ($s === null) return;

		$s = trim($s);
		if ($s === '') return;

		$payload[$key] = $s;
	}

	private function addIfInt(array &$payload, string $key, mixed $value): void {
		if ($value === null) {
			return;
		}

		if (is_int($value)) {
			if ($value > 0) {
				$payload[$key] = $value;
			}
			return;
		}

		if (is_string($value) && ctype_digit($value)) {
			$i = (int)$value;
			if ($i > 0) {
				$payload[$key] = $i;
			}
		}
	}

	private function addIfBoolInt(array &$payload, string $key, mixed $value): void {
		if ($value === null) {
			return;
		}

		if (is_bool($value)) {
			$payload[$key] = $value ? 1 : 0;
			return;
		}

		if (is_int($value)) {
			if ($value === 0 || $value === 1) {
				$payload[$key] = $value;
			}
			return;
		}

		if (is_string($value) && ctype_digit($value)) {
			$i = (int)$value;
			if ($i === 0 || $i === 1) {
				$payload[$key] = $i;
			}
		}
	}

	private function assertBoolIntMeta(array $meta, string $key): void {
		if (!array_key_exists($key, $meta)) {
			return;
		}

		$v = $meta[$key];

		if (is_bool($v)) {
			return;
		}

		if (is_int($v)) {
			if ($v === 0 || $v === 1) {
				return;
			}
			throw new \RuntimeException("metadata field '{$key}' must be 0 or 1 if provided.");
		}

		if (is_string($v) && ctype_digit($v)) {
			$i = (int)$v;
			if ($i === 0 || $i === 1) {
				return;
			}
		}

		throw new \RuntimeException("metadata field '{$key}' must be 0 or 1 if provided.");
	}

	/**
	 * @return array<int,string>
	 */
	private function normalizeStringArray(mixed $value, bool $upperHex, bool $lowercase): array {
		if ($value === null) {
			return [];
		}
		if (!is_array($value)) {
			throw new \RuntimeException('Expected array for payload field.');
		}

		$out = [];
		$seen = [];

		foreach ($value as $v) {
			$s = $this->asString($v);
			if ($s === null) {
				continue;
			}

			$s = trim($s);
			if ($s === '') {
				continue;
			}

			if ($upperHex) {
				$s = $this->asUpperHex($s);
				if ($s === '') {
					continue;
				}
			}

			if ($lowercase) {
				$s = strtolower($s);
			}

			if (isset($seen[$s])) {
				continue;
			}

			$seen[$s] = true;
			$out[] = $s;
		}

		return $out;
	}

	private function collectMeta(array $metadata, array $knownKeys): array {
		$out = [];

		foreach ($metadata as $k => $v) {
			if (in_array($k, $knownKeys, true)) {
				continue;
			}

			// Explicitly ignore queue/workflow control fields
			if (in_array($k, ['job_id', 'attempts', 'locked_until', 'claim_token', 'claimed_at', 'state', 'error_message'], true)) {
				continue;
			}

			if (in_array($k, ['action', 'collectionKey', 'collection_key'], true)) {
				continue;
			}

			$out[$k] = $v;
		}

		return $out;
	}
}
