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
final class XrmEmbeddingQueueExtractorUpsertAdditionalTest extends TestCase {

	private const UUID = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	private const VERSION = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
	private const REF = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

	public function testUpsertBuildsDomainMetadataAndFullStructuredContent(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([$this->job()], [
			'FROM base3_embedding_seen' => ['last_seen_version_hex' => self::VERSION, 'last_seen_collection_key' => 'customer'],
			'FROM base3system_sysentry e' => $this->entry(),
			'FROM xrm_contact' => ['firstname' => 'Ada', 'lastname' => 'Lovelace'],
			'FROM base3system_sysuseraccess' => ['ok' => 1],
			'FROM base3system_sysname' => ['name' => '  Ada Lovelace  ']
		], [
			'FROM base3system_systag' => [
				['tag' => ' Alpha '], ['tag' => 'ALPHA'], ['tag' => ' beta '], ['tag' => ''], ['tag' => null]
			],
			'FROM base3system_sysallocview' => [
				['uuid_hex' => strtolower(self::REF)], ['uuid_hex' => self::REF],
				['uuid_hex' => 'invalid'], ['uuid_hex' => ' '], ['uuid_hex' => '!!']
			]
		], $reads, $writes);

		$items = $this->extractor($db)->extract($this->context());

		$this->assertCount(1, $items);
		$item = $items[0];
		$this->assertInstanceOf(AgentContentItem::class, $item);
		$this->assertTrue($item->isUpsert());
		$this->assertSame('customer', $item->collectionKey);
		$this->assertSame('21', $item->id);
		$this->assertSame('application/x-xrm-sysentry-json', $item->contentType);
		$this->assertSame(hash('sha256', 'customer:' . self::UUID . ':' . self::VERSION), $item->hash);
		$this->assertFalse($item->isBinary);
		$this->assertSame(strlen(json_encode($item->content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $item->size);
		$this->assertSame([
			'content_uuid' => self::UUID,
			'content_version' => self::VERSION,
			'type_alias' => 'contact',
			'archive' => 0,
			'public' => 1,
			'tags' => ['alpha', 'beta'],
			'ref_uuids' => [self::REF],
			'name' => 'Ada Lovelace'
		], $item->metadata);
		$this->assertSame(['firstname' => 'Ada', 'lastname' => 'Lovelace'], $item->content['payload']);
		$this->assertSame(self::UUID, $item->content['sysentry']['uuid']);
		$this->assertSame(self::VERSION, $item->content['sysentry']['etag']);
		$this->assertSame('contact', $item->content['type']['alias']);
		$this->assertSame('xrm_contact', $item->content['type']['table']);
		$this->assertCount(1, $writes);
		$this->assertStringContainsString("SET state = 'running'", $writes[0]);
		$this->assertTrue($this->hasRead($reads, 'user_id = 1'));
		$this->assertTrue($this->hasRead($reads, "mode = 'visitor'"));
	}

	public function testPrivateArchivedEntryOmitsEmptyOptionalMetadata(): void {
		$reads = [];
		$writes = [];
		$entry = $this->entry(['archive' => 1, 'alias' => '', 'etag_hex' => '']);
		$db = $this->database([$this->job(['source_version_hex' => ''])], [
			'FROM base3system_sysentry e' => $entry,
			'FROM xrm_contact' => ['data' => 'present'],
			'FROM base3system_sysuseraccess' => null,
			'FROM base3system_sysname' => ['name' => '  ']
		], [
			'FROM base3system_systag' => [['tag' => ' ']],
			'FROM base3system_sysallocview' => [['uuid_hex' => 'garbage']]
		], $reads, $writes);

		$item = $this->extractor($db)->extract($this->context())[0];
		$this->assertSame(1, $item->metadata['archive']);
		$this->assertSame(0, $item->metadata['public']);
		$this->assertNull($item->metadata['content_version']);
		$this->assertArrayNotHasKey('type_alias', $item->metadata);
		$this->assertArrayNotHasKey('tags', $item->metadata);
		$this->assertArrayNotHasKey('ref_uuids', $item->metadata);
		$this->assertArrayNotHasKey('name', $item->metadata);
		$this->assertSame('', $item->content['sysentry']['etag']);
		$this->assertSame([], array_filter($writes, static fn(string $sql): bool => str_contains($sql, "state = 'superseded'")));
	}

	public function testDifferentSeenVersionInSameCollectionSupersedesBeforeLoadingPayload(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([$this->job()], [
			'FROM base3_embedding_seen' => ['last_seen_version_hex' => self::REF, 'last_seen_collection_key' => 'customer']
		], [], $reads, $writes);

		$this->assertSame([], $this->extractor($db)->extract($this->context()));
		$this->assertCount(2, $writes);
		$this->assertStringContainsString("SET state = 'superseded'", $writes[1]);
		$this->assertStringContainsString('job_id = 21', $writes[1]);
		$this->assertFalse($this->hasRead($reads, 'FROM base3system_sysentry e'));
	}

	public function testDifferentSeenCollectionDoesNotSupersedeCurrentWork(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([$this->job()], [
			'FROM base3_embedding_seen' => ['last_seen_version_hex' => self::REF, 'last_seen_collection_key' => 'other'],
			'FROM base3system_sysentry e' => $this->entry(),
			'FROM xrm_contact' => ['ok' => 1]
		], [], $reads, $writes);

		$this->assertCount(1, $this->extractor($db)->extract($this->context()));
		$this->assertCount(1, $writes);
		$this->assertFalse(str_contains(implode('\n', $writes), "state = 'superseded'"));
	}

	public function testMatchingVersionOrMissingSeenVersionRemainsProcessable(): void {
		foreach ([self::VERSION, ''] as $seenVersion) {
			$reads = [];
			$writes = [];
			$db = $this->database([$this->job()], [
				'FROM base3_embedding_seen' => ['last_seen_version_hex' => $seenVersion, 'last_seen_collection_key' => 'customer'],
				'FROM base3system_sysentry e' => $this->entry(),
				'FROM xrm_contact' => ['ok' => 1]
			], [], $reads, $writes);

			$this->assertCount(1, $this->extractor($db)->extract($this->context()));
			$this->assertCount(1, $writes);
		}
	}

	public function testMissingSysentryMarksUpsertPendingForRetry(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([$this->job(['attempts' => 2, 'source_version_hex' => ''])], [], [], $reads, $writes);

		$this->assertSame([], $this->extractor($db)->extract($this->context()));
		$this->assertCount(2, $writes);
		$this->assertStringContainsString("SET state = 'pending'", $writes[1]);
		$this->assertStringContainsString('missing payload / sysentry not found', $writes[1]);
	}

	public function testUnsafePayloadTableIsNeverQueried(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([$this->job(['source_version_hex' => ''])], [
			'FROM base3system_sysentry e' => $this->entry(['dbtable' => 'xrm_contact;DROP TABLE users'])
		], [], $reads, $writes);

		$this->assertSame([], $this->extractor($db)->extract($this->context()));
		$this->assertCount(2, $writes);
		$this->assertStringContainsString("state = 'pending'", $writes[1]);
		$this->assertFalse($this->hasRead($reads, 'DROP TABLE users'));
	}

	public function testMissingPayloadAndInvalidEntryIdentifiersAreRetried(): void {
		foreach ([null, ['dbtable' => '', 'id' => 4], ['dbtable' => 'xrm_contact', 'id' => 0]] as $override) {
			$reads = [];
			$writes = [];
			$entry = $override === null ? $this->entry() : $this->entry($override);
			$db = $this->database([$this->job(['source_version_hex' => ''])], [
				'FROM base3system_sysentry e' => $entry
			], [], $reads, $writes);

			$this->assertSame([], $this->extractor($db)->extract($this->context()));
			$this->assertStringContainsString("SET state = 'pending'", $writes[1]);
		}
	}

	public function testUnknownJobTypeIsPermanentlyRejected(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([$this->job(['job_type' => 'update', 'attempts' => 1])], [], [], $reads, $writes);

		$this->assertSame([], $this->extractor($db)->extract($this->context()));
		$this->assertStringContainsString("SET state = 'error'", $writes[1]);
		$this->assertStringContainsString('unsupported job_type', $writes[1]);
	}

	public function testMissingRequiredWorkIdentifiersAreRejected(): void {
		foreach ([
			['job_id' => 17, 'source_uuid_hex' => ''],
			['job_id' => 18, 'collection_key' => ''],
			['job_id' => 0, 'source_uuid_hex' => self::UUID]
		] as $override) {
			$reads = [];
			$writes = [];
			$job = $this->job($override);
			$db = $this->database([$job], [], [], $reads, $writes);

			$this->assertSame([], $this->extractor($db)->extract($this->context()));
			$this->assertFalse($this->hasRead($reads, 'FROM base3system_sysentry e'));
			$this->assertCount((int)$job['job_id'] > 0 ? 2 : 1, $writes);
			if ((int)$job['job_id'] > 0) {
				$this->assertStringContainsString("SET state = 'error'", $writes[1]);
			}
		}
	}


	public function testInvalidJobDoesNotPreventLaterDeleteAndUpsertItems(): void {
		$reads = [];
		$writes = [];
		$jobs = [
			$this->job(['job_id' => 11, 'job_type' => 'unknown']),
			$this->job(['job_id' => 12, 'job_type' => 'delete', 'collection_key' => 'archive', 'source_version_hex' => '']),
			$this->job(['job_id' => 13, 'source_version_hex' => ''])
		];
		$db = $this->database($jobs, [
			'FROM base3system_sysentry e' => $this->entry(),
			'FROM xrm_contact' => ['status' => 'ready']
		], [], $reads, $writes);

		$items = $this->extractor($db)->extract($this->context());
		$this->assertCount(2, $items);
		$this->assertSame(['12', '13'], array_map(static fn(AgentContentItem $item): string => $item->id, $items));
		$this->assertTrue($items[0]->isDelete());
		$this->assertTrue($items[1]->isUpsert());
		$this->assertSame('archive', $items[0]->collectionKey);
		$this->assertSame('customer', $items[1]->collectionKey);
		$this->assertCount(2, $writes);
		$this->assertStringContainsString("state = 'error'", $writes[1]);
	}

	public function testInvalidUtf8PayloadKeepsStructuredContentAndReportsUnknownSize(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([$this->job(['source_version_hex' => ''])], [
			'FROM base3system_sysentry e' => $this->entry(),
			'FROM xrm_contact' => ['invalid_utf8' => "\xB1"]
		], [], $reads, $writes);

		$item = $this->extractor($db)->extract($this->context())[0];
		$this->assertTrue($item->isUpsert());
		$this->assertSame(0, $item->size);
		$this->assertSame("\xB1", $item->content['payload']['invalid_utf8']);
		$this->assertSame('customer', $item->collectionKey);
	}

	private function job(array $override = []): array {
		return array_replace([
			'job_id' => 21, 'job_type' => 'upsert', 'attempts' => 1,
			'collection_key' => 'customer', 'source_uuid_hex' => self::UUID,
			'source_version_hex' => self::VERSION
		], $override);
	}

	private function entry(array $override = []): array {
		return array_replace([
			'id' => 7, 'type_id' => 3, 'archive' => 0,
			'etag_hex' => self::VERSION, 'created' => '2026-01-01 00:00:00',
			'changed' => '2026-01-04 01:02:03', 'alias' => 'contact',
			'dbtable' => 'xrm_contact'
		], $override);
	}

	private function context(): IAgentContext {
		return $this->createStub(IAgentContext::class);
	}

	private function extractor(IDatabase $db): XrmEmbeddingQueueExtractorAgentResource {
		return new XrmEmbeddingQueueExtractorAgentResource($db, $this->createStub(IAgentConfigValueResolver::class));
	}

	private function hasRead(array $reads, string $needle): bool {
		foreach ($reads as $sql) {
			if (str_contains($sql, $needle)) {
				return true;
			}
		}
		return false;
	}

	private function database(array $jobs, array $singles, array $multiples, array &$reads, array &$writes): IDatabase {
		$db = $this->createStub(IDatabase::class);
		$db->method('connected')->willReturn(true);
		$db->method('escape')->willReturnCallback(static fn(string $s): string => addslashes($s));
		$db->method('nonQuery')->willReturnCallback(static function(string $sql) use (&$writes): void {
			$writes[] = $sql;
		});
		$db->method('multiQuery')->willReturnCallback(static function(string $sql) use ($jobs, $multiples, &$reads): array {
			$reads[] = $sql;
			if (str_contains($sql, 'SELECT job_id') && str_contains($sql, 'FROM base3_embedding_job')) {
				return array_map(static fn(array $job): array => ['job_id' => $job['job_id']], $jobs);
			}
			if (str_contains($sql, 'HEX(source_uuid) AS source_uuid_hex')) {
				return $jobs;
			}
			foreach ($multiples as $needle => $rows) {
				if (str_contains($sql, $needle)) {
					return $rows;
				}
			}
			return [];
		});
		$db->method('singleQuery')->willReturnCallback(static function(string $sql) use ($singles, &$reads): ?array {
			$reads[] = $sql;
			foreach ($singles as $needle => $row) {
				if (str_contains($sql, $needle)) {
					return $row;
				}
			}
			return null;
		});
		return $db;
	}
}
