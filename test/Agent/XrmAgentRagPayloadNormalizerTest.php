<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Agent;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Agent\XrmAgentRagPayloadNormalizer;
use MissionBay\Dto\AgentEmbeddingChunk;

/**
 * @covers \MissionBayXrm\Agent\XrmAgentRagPayloadNormalizer
 */
class XrmAgentRagPayloadNormalizerTest extends TestCase {

	private function makeChunk(
		string $collectionKey = 'xrm',
		int $chunkIndex = 0,
		string $text = 'Hello',
		string $hash = 'h1',
		array $meta = ['content_uuid' => 'AABBCC']
	): AgentEmbeddingChunk {
		return new AgentEmbeddingChunk(
			collectionKey: $collectionKey,
			chunkIndex: $chunkIndex,
			text: $text,
			hash: $hash,
			metadata: $meta,
			vector: []
		);
	}

	public function testGetCollectionKeys(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$this->assertSame(['xrm'], $n->getCollectionKeys());
	}

	public function testBackendCollectionNameVectorSizeAndDistance(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$this->assertSame('xrm_content_v1', $n->getBackendCollectionName('xrm'));
		$this->assertSame(1536, $n->getVectorSize('xrm'));
		$this->assertSame('Cosine', $n->getDistance('xrm'));
	}

	public function testGetSchemaContainsExpectedKeysAndTypes(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$schema = $n->getSchema('xrm');

		$this->assertSame(['type' => 'keyword', 'index' => true], $schema['hash']);
		$this->assertSame(['type' => 'keyword', 'index' => true], $schema['collection_key']);
		$this->assertSame(['type' => 'keyword', 'index' => true], $schema['content_uuid']);
		$this->assertSame(['type' => 'keyword', 'index' => true], $schema['chunktoken']);
		$this->assertSame(['type' => 'integer', 'index' => true], $schema['chunk_index']);

		$this->assertSame(['type' => 'text', 'index' => true], $schema['name']);
		$this->assertSame(['type' => 'keyword', 'index' => true], $schema['type_alias']);

		$this->assertSame(['type' => 'integer', 'index' => true], $schema['archive']);
		$this->assertSame(['type' => 'integer', 'index' => true], $schema['public']);

		$this->assertSame(['type' => 'keyword', 'index' => true], $schema['tags']);
		$this->assertSame(['type' => 'keyword', 'index' => true], $schema['ref_uuids']);
	}

	public function testValidateCanonicalizesCollectionKey(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('XRM', 0, 't', 'h', ['content_uuid' => 'aa']);
		$n->validate($chunk);

		$this->assertSame('xrm', $chunk->collectionKey);
	}

	public function testValidateThrowsWhenCollectionKeyIsEmpty(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('', 0, 't', 'h', ['content_uuid' => 'aa']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('AgentEmbeddingChunk.collectionKey is required.');

		$n->validate($chunk);
	}

	public function testValidateThrowsWhenChunkIndexIsNegative(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', -1, 't', 'h', ['content_uuid' => 'aa']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('AgentEmbeddingChunk.chunkIndex must be >= 0.');

		$n->validate($chunk);
	}

	public function testValidateThrowsWhenTextIsEmpty(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, '   ', 'h', ['content_uuid' => 'aa']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('AgentEmbeddingChunk.text must be non-empty for XRM.');

		$n->validate($chunk);
	}

	public function testValidateThrowsWhenHashIsEmpty(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', '   ', ['content_uuid' => 'aa']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('AgentEmbeddingChunk.hash must be non-empty for XRM upsert.');

		$n->validate($chunk);
	}

	public function testValidateThrowsWhenContentUuidMissing(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', []);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("Missing required metadata field 'content_uuid' for XRM.");

		$n->validate($chunk);
	}

	public function testValidateThrowsWhenTagsIsNotArray(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', [
			'content_uuid' => 'aa',
			'tags' => 'x'
		]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("metadata field 'tags' must be an array if provided.");

		$n->validate($chunk);
	}

	public function testValidateThrowsWhenRefUuidsIsNotArray(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', [
			'content_uuid' => 'aa',
			'ref_uuids' => 'x'
		]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("metadata field 'ref_uuids' must be an array if provided.");

		$n->validate($chunk);
	}

	public function testValidateThrowsWhenNumChunksIsNotInt(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', [
			'content_uuid' => 'aa',
			'num_chunks' => 'x'
		]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("metadata field 'num_chunks' must be an integer if provided.");

		$n->validate($chunk);
	}

	public function testValidateThrowsWhenNumChunksIsZeroOrNegative(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', [
			'content_uuid' => 'aa',
			'num_chunks' => 0
		]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("metadata field 'num_chunks' must be > 0 if provided.");

		$n->validate($chunk);
	}

	public function testValidateThrowsWhenArchiveIsInvalidBoolInt(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', [
			'content_uuid' => 'aa',
			'archive' => 2
		]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("metadata field 'archive' must be 0 or 1 if provided.");

		$n->validate($chunk);
	}

	public function testValidateThrowsWhenPublicIsInvalidBoolInt(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', [
			'content_uuid' => 'aa',
			'public' => '2'
		]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("metadata field 'public' must be 0 or 1 if provided.");

		$n->validate($chunk);
	}

	public function testBuildPayloadIncludesRequiredFieldsAndChunkToken(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, ' Hello ', 'HASH', [
			'content_uuid' => 'aa-bb-cc',
			'type_alias' => 'task'
		]);

		$payload = $n->buildPayload($chunk);

		$this->assertSame('Hello', $payload['text']);
		$this->assertSame('HASH', $payload['hash']);
		$this->assertSame('xrm', $payload['collection_key']);
		$this->assertSame('AABBCC', $payload['content_uuid']);
		$this->assertSame('HASH', $payload['chunktoken']);
		$this->assertSame(0, $payload['chunk_index']);
		$this->assertSame('task', $payload['type_alias']);
	}

	public function testBuildPayloadChunkTokenAppendsIndexWhenChunkIndexPositive(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 3, 't', 'HASH', ['content_uuid' => 'aa']);

		$payload = $n->buildPayload($chunk);

		$this->assertSame('HASH-3', $payload['chunktoken']);
		$this->assertSame(3, $payload['chunk_index']);
	}

	public function testBuildPayloadOmitsNameWhenEmptyNoFallback(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', [
			'content_uuid' => 'aa',
			'name' => '   '
		]);

		$payload = $n->buildPayload($chunk);

		$this->assertArrayNotHasKey('name', $payload);
	}

	public function testBuildPayloadNormalizesTagsLowercaseUniqueAndRefUuidsUpperHexUnique(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', [
			'content_uuid' => 'aa',
			'tags' => [' Foo ', 'foo', 'BAR', '', '  '],
			'ref_uuids' => ['aa-bb', 'AABB', '  aabb  ', 'zz', '']
		]);

		$payload = $n->buildPayload($chunk);

		$this->assertSame(['foo', 'bar'], $payload['tags']);
		$this->assertSame(['AABB'], $payload['ref_uuids']);
	}

	public function testBuildPayloadCollectsUnknownMetaAndExcludesKnownAndWorkflowKeys(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', [
			'content_uuid' => 'aa',
			'type_alias' => 'x',
			'job_id' => 123,
			'attempts' => 2,
			'locked_until' => 'x',
			'state' => 'pending',
			'error_message' => 'x',
			'action' => 'upsert',
			'collectionKey' => 'xrm',
			'collection_key' => 'xrm',
			'custom' => 'keep',
			'nested' => ['k' => 'v']
		]);

		$payload = $n->buildPayload($chunk);

		$this->assertArrayHasKey('meta', $payload);
		$this->assertSame([
			'custom' => 'keep',
			'nested' => ['k' => 'v']
		], $payload['meta']);
	}

	public function testBuildPayloadAddsOptionalScalarFieldsWhenNonEmpty(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', [
			'content_uuid' => 'aa',
			'source_id' => 's1',
			'name' => 'Name',
			'content_id' => 'c1',
			'url' => 'https://example.test',
			'filename' => 'f.pdf',
			'lang' => 'de',
			'created_at' => '2020-01-01',
			'updated_at' => '2020-01-02'
		]);

		$payload = $n->buildPayload($chunk);

		$this->assertSame('s1', $payload['source_id']);
		$this->assertSame('Name', $payload['name']);
		$this->assertSame('c1', $payload['content_id']);
		$this->assertSame('https://example.test', $payload['url']);
		$this->assertSame('f.pdf', $payload['filename']);
		$this->assertSame('de', $payload['lang']);
		$this->assertSame('2020-01-01', $payload['created_at']);
		$this->assertSame('2020-01-02', $payload['updated_at']);
	}

	public function testBuildPayloadAddsOptionalIntsAndBoolIntsWhenValid(): void {
		$n = new XrmAgentRagPayloadNormalizer();

		$chunk = $this->makeChunk('xrm', 0, 't', 'h', [
			'content_uuid' => 'aa',
			'num_chunks' => '3',
			'archive' => true,
			'public' => 0
		]);

		$payload = $n->buildPayload($chunk);

		$this->assertSame(3, $payload['num_chunks']);
		$this->assertSame(1, $payload['archive']);
		$this->assertSame(0, $payload['public']);
	}
}
