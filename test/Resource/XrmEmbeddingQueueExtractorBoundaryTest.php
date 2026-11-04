<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use AssistantFoundation\Api\IAgentContext;
use Base3\Database\Api\IDatabase;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Dto\AgentContentItem;
use MissionBayXrm\Resource\XrmEmbeddingQueueExtractorAgentResource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MissionBayXrm\Resource\XrmEmbeddingQueueExtractorAgentResource
 */
final class XrmEmbeddingQueueExtractorBoundaryTest extends TestCase {

	private const UUID = 'AABBCCDDEEFF00112233445566778899';
	private const REF_UUID = '11223344556677889900AABBCCDDEEFF';

	public function testDeleteHashDependsOnCollectionAndNormalizesCaseOfUuidAndVersion(): void {
		$reads = [];
		$writes = [];
		$jobs = [
			$this->job(['job_id' => 41, 'job_type' => 'DELETE', 'collection_key' => ' folder ', 'source_uuid_hex' => strtolower(self::UUID), 'source_version_hex' => 'bb']),
			$this->job(['job_id' => 42, 'job_type' => 'delete', 'collection_key' => 'other', 'source_uuid_hex' => strtolower(self::UUID), 'source_version_hex' => 'bb'])
		];
		$db = $this->database($jobs, [], [], $reads, $writes);

		$items = $this->extractor($db)->extract($this->createStub(IAgentContext::class));
		$this->assertCount(2, $items);
		$this->assertSame('folder', $items[0]->collectionKey);
		$this->assertSame('BB', $items[0]->metadata['content_version']);
		$this->assertSame(self::UUID, $items[0]->metadata['content_uuid']);
		$this->assertSame(hash('sha256', 'folder:' . self::UUID . ':BB'), $items[0]->hash);
		$this->assertSame(hash('sha256', 'other:' . self::UUID . ':BB'), $items[1]->hash);
		$this->assertTrue($items[0]->isDelete());
		$this->assertCount(1, $writes);
		$this->assertCount(2, $reads);
	}

	public function testMissingAttemptsRowAllowsRetryInsteadOfTreatingJobAsFinished(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], [], $reads, $writes);
		$this->extractor($db)->fail($this->item('upsert', '53'), 'temporary failure');
		$this->assertCount(1, $reads);
		$this->assertCount(1, $writes);
		$this->assertStringContainsString('SELECT attempts', $reads[0]);
		$this->assertStringContainsString("SET state = 'pending'", $writes[0]);
		$this->assertStringContainsString('job_id = 53', $writes[0]);
	}

	public function testNegativeAttemptCounterIsClampedBeforeRetryDecision(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], ['FROM base3_embedding_job' => ['attempts' => -8]], [], $reads, $writes);
		$this->extractor($db)->fail($this->item('upsert', '59'), 'transient error', true);
		$this->assertCount(1, $writes);
		$this->assertStringContainsString("SET state = 'pending'", $writes[0]);
	}

	public function testAcknowledgingDeleteEscapesCollectionKeyInSeenUpdate(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], [], $reads, $writes);
		$this->extractor($db)->ack($this->item('delete', '75', "people's notes", ['content_uuid' => strtolower(self::UUID)]));
		$this->assertCount(2, $writes);
		$this->assertStringContainsString("SET state = 'done'", $writes[0]);
		$this->assertStringContainsString("people\\'s notes", $writes[1]);
		$this->assertStringContainsString("UNHEX('" . self::UUID . "')", $writes[1]);
	}

	public function testReferencesAreNormalizedAfterNonHexCharactersAreRemoved(): void {
		$reads = [];
		$writes = [];
		$entry = [
			'id' => 7, 'type_id' => 3, 'archive' => 0, 'etag_hex' => 'FF',
			'created' => '2026-01-01 00:00:00', 'changed' => '2026-01-01 00:01:00',
			'alias' => 'contact', 'dbtable' => 'xrm_contact'
		];
		$db = $this->database([$this->job(['source_version_hex' => ''])], [
			'FROM base3system_sysentry e' => $entry,
			'FROM xrm_contact' => ['name' => 'entry'],
			'FROM base3system_sysname' => ['name' => "  Jane's notes  "]
		], [
			'FROM base3system_systag' => [['tag' => '  Work '], ['tag' => 'work'], ['tag' => '  ']],
			'FROM base3system_sysallocview' => [
				['uuid_hex' => strtolower(self::REF_UUID)],
				['uuid_hex' => substr(self::REF_UUID, 0, 8) . '-' . substr(self::REF_UUID, 8)],
				['uuid_hex' => 'GG'],
				['uuid_hex' => '']
			]
		], $reads, $writes);

		$items = $this->extractor($db)->extract($this->createStub(IAgentContext::class));
		$this->assertCount(1, $items);
		$this->assertSame([self::REF_UUID], $items[0]->metadata['ref_uuids']);
		$this->assertSame(['work'], $items[0]->metadata['tags']);
		$this->assertSame("Jane's notes", $items[0]->metadata['name']);
		$this->assertSame(0, $items[0]->metadata['public']);
		$this->assertCount(1, $writes);
	}

	public function testInvalidClaimedSourceDoesNotPreventLaterDeletion(): void {
		$reads = [];
		$writes = [];
		$jobs = [
			$this->job(['job_id' => 82, 'job_type' => 'upsert', 'source_uuid_hex' => '', 'attempts' => 0]),
			$this->job(['job_id' => 83, 'job_type' => 'delete', 'source_version_hex' => ''])
		];
		$db = $this->database($jobs, [], [], $reads, $writes);

		$items = $this->extractor($db)->extract($this->createStub(IAgentContext::class));
		$this->assertCount(1, $items);
		$this->assertSame('83', $items[0]->id);
		$this->assertCount(2, $writes);
		$this->assertStringContainsString('state = \'error\'', $writes[1]);
	}

	private function job(array $overrides = []): array {
		return array_replace([
			'job_id' => 41, 'job_type' => 'upsert', 'attempts' => 1,
			'collection_key' => 'documents', 'source_uuid_hex' => self::UUID,
			'source_version_hex' => 'FF'
		], $overrides);
	}

	private function item(string $action, string $id, string $collection = 'documents', array $metadata = []): AgentContentItem {
		return new AgentContentItem(
			action: $action,
			collectionKey: $collection,
			id: $id,
			hash: 'version',
			contentType: 'application/json',
			content: [],
			isBinary: false,
			size: 0,
			metadata: $metadata
		);
	}

	private function extractor(IDatabase $db): XrmEmbeddingQueueExtractorAgentResource {
		return new XrmEmbeddingQueueExtractorAgentResource($db, $this->createStub(IAgentConfigValueResolver::class));
	}

	private function database(array $jobs, array $singleResults, array $multipleResults, array &$reads, array &$writes): IDatabase {
		$db = $this->createStub(IDatabase::class);
		$db->method('connected')->willReturn(true);
		$db->method('escape')->willReturnCallback(static fn(string $value): string => addslashes($value));
		$db->method('nonQuery')->willReturnCallback(static function(string $sql) use (&$writes): void {
			$writes[] = $sql;
		});
		$db->method('multiQuery')->willReturnCallback(static function(string $sql) use ($jobs, $multipleResults, &$reads): array {
			$reads[] = $sql;
			if (str_contains($sql, 'SELECT job_id') && str_contains($sql, 'FROM base3_embedding_job')) {
				return array_map(static fn(array $job): array => ['job_id' => $job['job_id']], $jobs);
			}
			if (str_contains($sql, 'HEX(source_uuid) AS source_uuid_hex')) {
				return $jobs;
			}
			foreach ($multipleResults as $needle => $rows) {
				if (str_contains($sql, $needle)) {
					return $rows;
				}
			}
			return [];
		});
		$db->method('singleQuery')->willReturnCallback(static function(string $sql) use ($singleResults, &$reads): ?array {
			$reads[] = $sql;
			foreach ($singleResults as $needle => $result) {
				if (str_contains($sql, $needle)) {
					return $result;
				}
			}
			return null;
		});
		return $db;
	}
}
