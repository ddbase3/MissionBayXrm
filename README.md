# MissionBay XRM Embedding Pipeline

> **License:** GNU GPL v3 (see **License** section)

This repository/plugin contains a complete, production-oriented embedding pipeline that synchronizes **XRM sysentry content** into a **Qdrant** vector database.

The pipeline is designed for **incremental updates**, **deduplication**, **replacement semantics**, and **delete propagation**.

---

## Overview

The pipeline has three main parts:

1. **Worker Job A (Enqueue)**

   * Scans XRM sysentries incrementally
   * Enqueues **upsert** and **delete** jobs into a DB-backed queue
   * Persists runtime state (cursors, last-run timestamps) in the BASE3 **IStateStore**

2. **Embedding Flow (Coordinator Node)**

   * Claims queue jobs (Extractor)
   * Loads and normalizes content (Extractor + Parsers)
   * Produces semantic chunks (Chunker)
   * Generates embeddings (Embedder / Embedding cache)
   * Writes points into Qdrant (Vector store)
   * Reports success/failure back to the Extractor (ack/fail)

3. **Worker Job D (Cleanup, optional / planned)**

   * Daily retention cleanup (superseded jobs, old errors, stale locks, deleted/archived content)
   * Uses IStateStore to track last cleanup run

---

## Architecture

### Data Model

The pipeline uses two database tables:

* `base3_embedding_job`

  * Queue table for work units
  * Contains `job_type` = `upsert` or `delete`
  * Contains `state` = `pending | running | done | error | superseded`

* `base3_embedding_seen`

  * Tracks what the pipeline last observed for a sysentry UUID
  * Stores last seen version, timestamps, and delete markers

Runtime scheduling and cursors are stored via:

* `Base3\State\Api\IStateStore`

  * Backed by `base3_statestore` (JSON values, optional TTL)

---

## Quickstart

### 1) Configure Qdrant

You need:

* Qdrant endpoint
* Qdrant API key

In BASE3 config (example):

* section: `qdrant`

  * key: `endpoint`
  * key: `apikey`

### 2) Configure OpenAI Embeddings

You need:

* OpenAI API key

In BASE3 config (example):

* section: `openai`

  * key: `apikey`

### 3) Run Worker A (Enqueue)

Worker A scans XRM and inserts queue jobs.

* Minimum interval: **15 minutes** (900 seconds)
* Cursor: `last_changed` timestamp

### 4) Run Embedding Worker (Flow)

The embedding worker executes the JSON-defined flow.

---

## Flow Example (Embedding)

Below is a working example flow configuration.

```json
{
	"nodes": [
		{
			"id": "embedding",
			"type": "aiembeddingnode",
			"docks": {
				"extractor": ["extractor"],
				"parser": [
					"accountparser",
					"addressparser",
					"contactparser",
					"dateparser",
					"fileparser",
					"productparser",
					"projectparser",
					"resourceparser",
					"tagparser",
					"taskparser",
					"textparser",
					"parser"
				],
				"chunker": ["chunker"],
				"embedder": ["embeddingcache"],
				"vectordb": ["vectordb"],
				"logger": ["logger"]
			},
			"inputs": {
				"mode": "replace",
				"debug": true,
				"debug_preview_len": 180
			}
		}
	],
	"resources": [
		{
			"id": "extractor",
			"type": "xrmembeddingqueueextractoragentresource",
			"config": {
				"claim_limit": {"mode": "fixed", "value": 5}
			}
		},

		{"id": "accountparser", "type": "xrmaccountmetaparseragentresource"},
		{"id": "addressparser", "type": "xrmaddressmetaparseragentresource"},
		{"id": "contactparser", "type": "xrmcontactmetaparseragentresource"},
		{"id": "dateparser", "type": "xrmdatemetaparseragentresource"},
		{"id": "fileparser", "type": "xrmfilemetaparseragentresource"},
		{"id": "productparser", "type": "xrmproductmetaparseragentresource"},
		{"id": "projectparser", "type": "xrmprojectmetaparseragentresource"},
		{"id": "resourceparser", "type": "xrmresourcemetaparseragentresource"},
		{"id": "tagparser", "type": "xrmtagmetaparseragentresource"},
		{"id": "taskparser", "type": "xrmtaskmetaparseragentresource"},
		{"id": "textparser", "type": "xrmtextmetaparseragentresource"},
		{"id": "parser", "type": "structuredobjectparseragentresource"},

		{
			"id": "chunker",
			"type": "xrmchunkeragentresource",
			"config": {
				"max_length": {"mode": "fixed", "value": 2000},
				"min_length": {"mode": "fixed", "value": 500},
				"overlap": {"mode": "fixed", "value": 50}
			}
		},

		{
			"id": "embeddingcache",
			"type": "embeddingcacheagentresource",
			"docks": {
				"embedding": ["embedder"]
			},
			"config": {
				"model": {"mode": "fixed", "value": "text-embedding-3-small"}
			}
		},
		{
			"id": "embedder",
			"type": "openaiembeddingmodelagentresource",
			"config": {
				"apikey": {"mode": "config", "section": "openai", "key": "apikey"},
				"model": {"mode": "fixed", "value": "text-embedding-3-small"}
			}
		},

		{
			"id": "vectordb",
			"type": "qdrantvectorstoreagentresource",
			"config": {
				"endpoint": {"mode": "config", "section": "qdrant", "key": "endpoint"},
				"apikey": {"mode": "config", "section": "qdrant", "key": "apikey"},
				"create_payload_indexes": {"mode": "fixed", "value": true}
			}
		},

		{"id": "logger", "type": "loggerresource"}
	]
}
```

---

## Components

### AiEmbeddingNode (Coordinator)

`AiEmbeddingNode` orchestrates the full pipeline.

**Inputs**

* `mode` (`skip | append | replace`)

  * `skip`: if a chunk with the same `hash` already exists in the vector store, the item is skipped.
  * `append`: always add new points (not recommended for mutable CRM content).
  * `replace`: delete all old points for the same `content_uuid`, then upsert the new ones.

* `debug` (`bool`)

  * If enabled, logs are echoed to CLI in addition to being sent to the logger resource.

* `debug_preview_len` (`int`)

  * Limits text preview length in debug output.

**Docks**

* `extractor` → `IAgentContentExtractor`
* `parser` → `IAgentContentParser`
* `chunker` → `IAgentChunker`
* `embedder` → `IAiEmbeddingModel`
* `vectordb` → `IAgentVectorStore`
* `logger` → `ILogger` (optional)

**Outputs**

* `stats` (array)

  * Execution statistics, counters, errors, etc.

---

### Extractor: XrmEmbeddingQueueExtractorAgentResource

The extractor is the **queue owner**.

It implements `IAgentContentExtractor` and is responsible for:

* Claiming jobs from `base3_embedding_job`
* Loading sysentry payload and metadata
* Building `AgentContentItem`
* Marking queue states via `ack()` / `fail()`

**Claiming semantics**

* Selects `pending` jobs whose lock is expired or missing
* Marks them `running` and sets `locked_until` to prevent concurrent processing
* Increases `attempts`

**Superseded behavior**

* The enqueue job can mark older `pending` jobs as `superseded` when a newer upsert is queued.
* The extractor may also detect jobs that are obsolete compared to `base3_embedding_seen`.

---

### Parsers

The pipeline uses multiple parsers, ordered by `getPriority()`.

* Domain parsers (`xrm*metaparseragentresource`)

  * Extract and normalize typed metadata
  * Remove sensitive fields when required
  * Provide consistent domain fields to downstream chunking and payload normalization

* `StructuredObjectParserAgentResource`

  * Generic fallback parser
  * Ensures that structured JSON payloads are converted into a normalized representation

The first parser that `supports()` an item is used.

---

### Chunker: XrmChunkerAgentResource

Creates semantically meaningful chunks.

**Config**

* `max_length`

  * Maximum chunk size
* `min_length`

  * Minimum chunk size before forcing merge
* `overlap`

  * Character overlap between neighboring chunks

**Metadata propagation**

* Item metadata is merged into every chunk
* Chunk-specific metadata may be added by the chunker
* The coordinator adds:

  * `num_chunks` (same value on all chunks)
  * `chunk_index`

---

### Embedder: OpenAIEmbeddingModelAgentResource

Creates numeric vectors from chunk text.

* Input: array of chunk texts
* Output: array of vectors (`float[]`)

The coordinator assigns vectors back to each `AgentEmbeddingChunk`.

---

### Embedding Cache: EmbeddingCacheAgentResource

Optional caching layer that wraps an embedder.

* Prevents repeated embedding calls for identical text+model inputs
* Can improve cost and throughput for unchanged content

---

### Vector Store: QdrantVectorStoreAgentResource

Persists points in Qdrant.

**Key behaviors**

* Ensures collections exist
* Optionally ensures payload indexes based on the normalizer schema
* Supports:

  * `upsert(AgentEmbeddingChunk)`
  * `existsByHash(collectionKey, hash)`
  * `deleteByFilter(collectionKey, filter)`
  * `search(collectionKey, vector, limit, minScore, filterSpec)`

**Deterministic point IDs**

* If `hash` is present: point ID is UUIDv5(hash + chunkIndex)
* This makes upserts stable and avoids accidental duplicates

---

### Payload Normalizer: XrmAgentRagPayloadNormalizer

Maps `AgentEmbeddingChunk` to a Qdrant payload.

**Important agreements**

* `name` is stored as a top-level payload field (not inside meta)
* `name` is indexed as `text`
* Domain metadata is preserved as `payload.meta` (not indexed)

**Filterable fields (indexed)**

* `hash` (keyword)
* `collection_key` (keyword)
* `content_uuid` (keyword)
* `chunktoken` (keyword)
* `chunk_index` (integer)
* `type_alias` (keyword)
* `archive` (integer 0|1)
* `public` (integer 0|1)
* `tags` (keyword array)
* `ref_uuids` (keyword array)

---

## Jobs

### Worker Job A: XrmEmbeddingEnqueueJob

**Purpose**

* Incrementally scans sysentries based on `changed` timestamp
* Enqueues:

  * `upsert` for changed/created entries
  * `delete` when a previously seen UUID disappears

**State handling**

Job A uses `IStateStore` to avoid a custom checkpoint table.

State keys:

* `missionbay.xrm.embedding.sysentry.last_changed`
* `missionbay.xrm.embedding.sysentry.last_run_at`

**Minimum interval**

* Job A runs at most every 900 seconds.
* If triggered earlier, it returns a "Skip" message.

**Superseding policy**

When inserting a new upsert job for the same `(source_uuid, collection_key)`, Job A:

* Marks older `pending` upsert jobs as `superseded`
* Does **not** touch `running` jobs

This avoids race conditions while still preventing queue bloat.

---

### Worker Job D: Cleanup (planned)

A daily cleanup job is recommended for retention.

Potential responsibilities:

* Remove old `superseded` jobs beyond retention window
* Remove `done` jobs older than retention window
* Remove `error` jobs older than retention window or beyond max attempts
* Unlock stale `running` jobs with expired `locked_until`
* Optionally prune `base3_embedding_seen` entries that are deleted for a long time

This job should also track `last_cleanup_at` via `IStateStore`, e.g.

* `missionbay.xrm.embedding.cleanup.last_run_at`

---

## Embedding Stages (Detailed)

This section describes every step as executed by `AiEmbeddingNode`.

### Stage 0: Input Validation

* `mode` must be one of `skip | append | replace`
* `embedder` and `vectordb` must be present

### Stage 1: Extraction (Claim)

* For each configured extractor:

  * Call `extract(context)`
  * Collect returned `AgentContentItem` and bind the extractor as its owner

**Failure handling**

* Extractor errors are counted but do not abort the full run.

### Stage 2: Delete or Upsert routing

* If `item.action == delete`:

  * execute delete stage
* Else:

  * execute upsert stage

### Stage 3a: Delete stage

* Requires `metadata.content_uuid`
* Calls vector store:

  * `deleteByFilter(collectionKey, {"content_uuid": <uuid>})`

If no exception is thrown, the delete stage is considered successful.

### Stage 3b: Replace / Dedup stage

* If `mode == skip` and `hash` exists:

  * `existsByHash(collectionKey, hash)` → skip if true

* If `mode == replace`:

  * Requires `metadata.content_uuid`
  * `deleteByFilter(collectionKey, {"content_uuid": <uuid>})`
  * This removes the previous version completely.

### Stage 4: Parsing

* Iterates parsers ordered by `getPriority()`
* Uses the first parser where `supports(item)` is true
* Calls `parse(item)` to produce `AgentParsedContent`

If parsing fails, the item is treated as failed and `fail()` is invoked.

### Stage 5: Chunking

* Iterates chunkers ordered by `getPriority()`
* Uses the first chunker where `supports(parsed)` is true
* Calls `chunk(parsed)` → returns raw chunks

Raw chunk format (expected):

* `text`: string
* `meta`: optional object

### Stage 6: Chunk normalization

* Empty chunk texts are dropped
* A normalized list is built so `num_chunks` is stable

For each normalized chunk:

* Merge metadata:

  * `item.metadata`
  * `parsed.metadata`
  * `rawChunk.meta`
* Add `num_chunks` to each chunk metadata
* Create `AgentEmbeddingChunk`:

  * `collectionKey`
  * `chunkIndex`
  * `text`
  * `hash`
  * `metadata`

### Stage 7: Embedding

* Collect all chunk texts
* Call `embedder->embed(texts)`
* Assign returned vectors back to corresponding chunks

Empty vectors are treated as embedding failures for the chunk.

### Stage 8: Vector store upsert

For each chunk:

* Skip empty text
* Skip if vector missing
* `vectordb->upsert(chunk)`

### Stage 9: Ack / Fail

For each processed item:

* If successful → `extractor->ack(item, resultMeta)`
* If failed → `extractor->fail(item, errorMessage, retryHint)`

**Hard rule**

* Only ACK if processing was truly successful.
* For upsert: requires `stored > 0`.

---

## How to Query Qdrant by Sysentry UUID

The payload field is `content_uuid` (upper hex string).

Example:

```bash
curl -s -X POST "<QDRANT_ENDPOINT>/collections/xrm_content_v1/points/scroll" \
	-H "Content-Type: application/json" \
	-H "api-key: <QDRANT_API_KEY>" \
	-d '{"filter":{"must":[{"key":"content_uuid","match":{"value":"<UUID_HEX>"}}]},"limit":50,"with_payload":true,"with_vector":false}' \
| jq -r '.result.points[] | {chunk_index:.payload.chunk_index,chunktoken:.payload.chunktoken,type:.payload.type_alias,name:.payload.name,text:(.payload.text[0:200])}'
```

---

## License (GPL v3)

This project is licensed under the **GNU General Public License, Version 3**.

You may:

* Use
* Modify
* Redistribute

Under the conditions of GPLv3, including:

* Source code availability for distributed derivatives
* Same license for derivative works

A copy of the license should be included as `LICENSE`.

If you did not receive a copy, see the GNU website for the full text of the GPL v3.
