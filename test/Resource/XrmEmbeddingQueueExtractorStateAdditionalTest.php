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

namespace Base3\MissionBayXrm\Test\Resource;

use Base3\AssistantFoundation\Api\IAgentContext;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\MissionBay\Api\IAgentConfigValueResolver;
use Base3\MissionBay\Dto\AgentContentItem;
use Base3\MissionBayXrm\Resource\XrmEmbeddingQueueExtractorAgentResource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Base3\MissionBayXrm\Resource\XrmEmbeddingQueueExtractorAgentResource
 */
final class XrmEmbeddingQueueExtractorStateAdditionalTest extends TestCase {

	private const UUID = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

	public function testConfiguredClaimLimitIsPassedToThePendingJobQuery(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], $reads, $writes);
		$resolver = $this->createStub(IAgentConfigValueResolver::class);
		$resolver->method('resolveValue')->willReturn('17');
		$extractor = new XrmEmbeddingQueueExtractorAgentResource($db, $resolver, 'test-claim');
		$extractor->setConfig(['claim_limit' => ['mode' => 'fixed', 'value' => 17]]);

		$this->assertSame([], $extractor->extract($this->context()));
		$this->assertCount(1, $reads);
		$this->assertStringContainsString('LIMIT 17', $reads[0]);
		$this->assertSame(['claim_limit' => ['mode' => 'fixed', 'value' => 17]], $extractor->getConfig());
		$this->assertSame('test-claim', $extractor->getId());
	}

	public function testNegativeResolvedLimitFallsBackToFive(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], $reads, $writes);
		$resolver = $this->createStub(IAgentConfigValueResolver::class);
		$resolver->method('resolveValue')->willReturn(-10);
		$extractor = new XrmEmbeddingQueueExtractorAgentResource($db, $resolver);
		$extractor->setConfig(['claim_limit' => -10]);

		$this->assertSame([], $extractor->extract($this->context()));
		$this->assertStringContainsString('LIMIT 5', $reads[0]);
	}

	public function testClaimedIdentifiersAreCastToIntegersBeforeLocking(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([['job_id' => '12) OR 1=1'], ['job_id' => '5']], [], $reads, $writes);

		$this->assertSame([], $this->extractor($db)->extract($this->context()));
		$this->assertCount(1, $writes);
		$this->assertStringContainsString('job_id IN (12,5)', $writes[0]);
		$this->assertStringContainsString('attempts = attempts + 1', $writes[0]);
		$this->assertStringContainsString('INTERVAL 10 MINUTE', $writes[0]);
		$this->assertStringNotContainsString('OR 1=1', implode('\n', $writes));
	}

	public function testMissingClaimedRowsStillPreservesExistingLockUpdate(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([['job_id' => 6]], [], $reads, $writes);

		$this->assertSame([], $this->extractor($db)->extract($this->context()));
		$this->assertCount(2, $reads);
		$this->assertCount(1, $writes);
		$this->assertStringContainsString("state = 'running'", $writes[0]);
	}

	public function testDeleteItemUsesVersionedHashWithoutReadingSource(): void {
		$reads = [];
		$writes = [];
		$job = $this->deleteJob();
		$db = $this->database([['job_id' => 31]], [$job], $reads, $writes);

		$items = $this->extractor($db)->extract($this->context());
		$this->assertCount(1, $items);
		$this->assertTrue($items[0]->isDelete());
		$this->assertSame('31', $items[0]->id);
		$this->assertSame('documents', $items[0]->collectionKey);
		$this->assertSame(hash('sha256', 'documents:' . self::UUID . ':BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'), $items[0]->hash);
		$this->assertSame(['content_uuid' => self::UUID, 'content_version' => 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'], $items[0]->metadata);
		$this->assertCount(2, $reads);
		$this->assertCount(1, $writes);
	}

	public function testMultipleDeleteJobsRemainSeparateAndInLoadedOrder(): void {
		$reads = [];
		$writes = [];
		$first = $this->deleteJob(['job_id' => 44]);
		$second = $this->deleteJob(['job_id' => 33, 'collection_key' => 'images', 'source_uuid_hex' => 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC']);
		$db = $this->database([['job_id' => 44], ['job_id' => 33]], [$first, $second], $reads, $writes);

		$items = $this->extractor($db)->extract($this->context());
		$this->assertSame(['44', '33'], array_map(static fn(AgentContentItem $item): string => $item->id, $items));
		$this->assertSame(['documents', 'images'], array_map(static fn(AgentContentItem $item): string => $item->collectionKey, $items));
		$this->assertCount(1, $writes);
	}

	public function testAckForUpsertOnlyMarksJobDone(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], $reads, $writes);

		$this->extractor($db)->ack($this->item('upsert', '57', 'documents', ['content_uuid' => self::UUID]));
		$this->assertCount(1, $writes);
		$this->assertStringContainsString("SET state = 'done'", $writes[0]);
		$this->assertStringContainsString('job_id = 57', $writes[0]);
		$this->assertFalse(str_contains($writes[0], 'base3_embedding_seen'));
	}

	public function testAckForDeleteWithoutSourceUuidDoesNotMarkSeenDeleted(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], $reads, $writes);

		$this->extractor($db)->ack($this->item('delete', '58', 'documents', []));
		$this->assertCount(1, $writes);
		$this->assertStringContainsString("state = 'done'", $writes[0]);
	}

	public function testAckForDeleteTrimsCollectionAndNormalizesUuid(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], $reads, $writes);

		$this->extractor($db)->ack($this->item('delete', '59', ' news ', ['content_uuid' => ' aa11ff ' ]));
		$this->assertCount(2, $writes);
		$this->assertStringContainsString('job_id = 59', $writes[0]);
		$this->assertStringContainsString('base3_embedding_seen', $writes[1]);
		$this->assertStringContainsString("UNHEX('AA11FF')", $writes[1]);
		$this->assertStringContainsString("last_seen_collection_key = 'news'", $writes[1]);
	}

	public function testAckForZeroOrNegativeJobIdsNeverUpdatesDatabase(): void {
		foreach (['0', '-1', 'invalid'] as $id) {
			$reads = [];
			$writes = [];
			$db = $this->database([], [], $reads, $writes);
			$this->extractor($db)->ack($this->item('delete', $id, 'documents', ['content_uuid' => self::UUID]));
			$this->assertSame([], $writes);
		}
	}

	public function testAckWhenDisconnectedDoesNotTouchDatabase(): void {
		$writes = [];
		$db = $this->createStub(IDatabase::class);
		$db->method('connected')->willReturn(false);
		$db->method('nonQuery')->willReturnCallback(static function(string $sql) use (&$writes): void {
			$writes[] = $sql;
		});

		$this->extractor($db)->ack($this->item('delete', '58', 'documents', ['content_uuid' => self::UUID]));
		$this->assertSame([], $writes);
	}

	public function testFailRetriesUntilFifthAttemptThenBecomesPermanentError(): void {
		foreach ([0 => 'pending', 4 => 'pending', 5 => 'error', 8 => 'error'] as $attempts => $state) {
			$reads = [];
			$writes = [];
			$db = $this->database([], [], $reads, $writes, ['attempts' => $attempts]);
			$this->extractor($db)->fail($this->item('upsert', '90'), 'failure', true);
			$this->assertCount(1, $writes);
			$this->assertStringContainsString("SET state = '$state'", $writes[0]);
			$this->assertStringContainsString('job_id = 90', $writes[0]);
		}
	}

	public function testFailWithoutRetryAlwaysUsesPermanentErrorState(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], $reads, $writes, ['attempts' => 1]);

		$this->extractor($db)->fail($this->item('upsert', '92'), 'do not retry', false);
		$this->assertStringContainsString("SET state = 'error'", $writes[0]);
	}

	public function testErrorMessageIsEscapedAndTruncatedToFourThousandCharacters(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], $reads, $writes, ['attempts' => 1]);
		$message = str_repeat('a', 3999) . "'" . str_repeat('z', 20);
		$this->extractor($db)->fail($this->item('upsert', '93'), $message, false);

		$this->assertCount(1, $writes);
		$this->assertStringContainsString(str_repeat('a', 3999) . "\\'", $writes[0]);
		$this->assertStringNotContainsString('zzzzz', $writes[0]);
	}

	public function testFailWithMissingJobIdOrDisconnectedDatabaseDoesNothing(): void {
		$reads = [];
		$writes = [];
		$db = $this->database([], [], $reads, $writes);
		$this->extractor($db)->fail($this->item('upsert', '0'), 'failure');
		$this->assertSame([], $writes);
		$this->assertSame([], $reads);

		$disconnected = $this->createMock(IDatabase::class);
		$disconnected->expects($this->once())->method('connect');
		$disconnected->expects($this->once())->method('connected')->willReturn(false);
		$disconnected->expects($this->never())->method('singleQuery');
		$disconnected->expects($this->never())->method('nonQuery');
		$this->extractor($disconnected)->fail($this->item('upsert', '95'), 'failure');
	}

	private function item(string $action, string $id, string $collection = 'documents', array $metadata = []): AgentContentItem {
		return new AgentContentItem(
			action: $action,
			collectionKey: $collection,
			id: $id,
			hash: 'version-hash',
			contentType: 'application/json',
			content: [],
			isBinary: false,
			size: 0,
			metadata: $metadata
		);
	}

	private function deleteJob(array $overrides = []): array {
		return array_replace([
			'job_id' => 31, 'job_type' => 'delete', 'attempts' => 1,
			'collection_key' => 'documents',
			'source_uuid_hex' => self::UUID,
			'source_version_hex' => 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'
		], $overrides);
	}

	private function context(): IAgentContext {
		return $this->createStub(IAgentContext::class);
	}

	private function extractor(IDatabase $db): XrmEmbeddingQueueExtractorAgentResource {
		return new XrmEmbeddingQueueExtractorAgentResource($db, $this->createStub(IAgentConfigValueResolver::class));
	}

	private function database(array $pending, array $running, array &$reads, array &$writes, ?array $attempts = null): IDatabase {
		$db = $this->createStub(IDatabase::class);
		$db->method('connected')->willReturn(true);
		$db->method('escape')->willReturnCallback(static fn(string $s): string => addslashes($s));
		$db->method('nonQuery')->willReturnCallback(static function(string $sql) use (&$writes): void {
			$writes[] = $sql;
		});
		$db->method('multiQuery')->willReturnCallback(static function(string $sql) use ($pending, $running, &$reads): array {
			$reads[] = $sql;
			if (str_contains($sql, 'SELECT job_id') && str_contains($sql, 'FROM base3_embedding_job')) {
				return $pending;
			}
			if (str_contains($sql, 'HEX(source_uuid) AS source_uuid_hex')) {
				return $running;
			}
			return [];
		});
		$db->method('singleQuery')->willReturnCallback(static function(string $sql) use ($attempts, &$reads): ?array {
			$reads[] = $sql;
			return $attempts;
		});
		return $db;
	}
}
