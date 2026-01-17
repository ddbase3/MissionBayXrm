<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Resource\XrmEmbeddingQueueExtractorAgentResource;
use Base3\Database\Api\IDatabase;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Api\IAgentContext;
use MissionBay\Dto\AgentContentItem;

/**
 * @covers \MissionBayXrm\Resource\XrmEmbeddingQueueExtractorAgentResource
 */
class XrmEmbeddingQueueExtractorAgentResourceTest extends TestCase {

	private function makeContextStub(): IAgentContext {
		// Extractor does not read context variables in current implementation.
		return $this->createStub(IAgentContext::class);
	}

	public function testGetName(): void {
		$this->assertSame('xrmembeddingqueueextractoragentresource', XrmEmbeddingQueueExtractorAgentResource::getName());
	}

	public function testGetDescription(): void {
		$db = $this->createStub(IDatabase::class);
		$resolver = $this->createStub(IAgentConfigValueResolver::class);

		$r = new XrmEmbeddingQueueExtractorAgentResource($db, $resolver, 'x1');

		$this->assertSame(
			'Claims embedding jobs from base3_embedding_job and returns AgentContentItem work units.',
			$r->getDescription()
		);
	}

	public function testSetConfigUsesDefaultWhenLimitIsMissingOrInvalid(): void {
		$db = $this->createStub(IDatabase::class);

		$resolver = $this->createStub(IAgentConfigValueResolver::class);
		$resolver->method('resolveValue')->willReturnCallback(function ($v) {
			return $v;
		});

		$r = new XrmEmbeddingQueueExtractorAgentResource($db, $resolver, 'x2');

		// Missing claim_limit -> defaults internally, we assert by inspecting LIMIT in SQL.
		$dbMock = $this->createMock(IDatabase::class);
		$dbMock->expects($this->once())->method('connect');
		$dbMock->expects($this->once())->method('connected')->willReturn(true);

		// No rows -> extractor returns [] after first SELECT.
		$dbMock->expects($this->once())
			->method('multiQuery')
			->with($this->callback(function (string $sql): bool {
				return str_contains($sql, 'FROM base3_embedding_job')
					&& str_contains($sql, "WHERE state = 'pending'")
					&& str_contains($sql, 'LIMIT 5');
			}))
			->willReturn([]);

		$dbMock->expects($this->never())->method('nonQuery');

		$r2 = new XrmEmbeddingQueueExtractorAgentResource($dbMock, $resolver, 'x3');

		$r2->setConfig([]);
		$out = $r2->extract($this->makeContextStub());

		$this->assertSame([], $out);

		// Invalid claim_limit <= 0 -> fallback to default (5) verified by LIMIT.
		$dbMock2 = $this->createMock(IDatabase::class);
		$dbMock2->expects($this->once())->method('connect');
		$dbMock2->expects($this->once())->method('connected')->willReturn(true);

		$dbMock2->expects($this->once())
			->method('multiQuery')
			->with($this->callback(function (string $sql): bool {
				return str_contains($sql, 'LIMIT 5');
			}))
			->willReturn([]);

		$dbMock2->expects($this->never())->method('nonQuery');

		$r3 = new XrmEmbeddingQueueExtractorAgentResource($dbMock2, $resolver, 'x4');
		$r3->setConfig(['claim_limit' => 0]);

		$out2 = $r3->extract($this->makeContextStub());
		$this->assertSame([], $out2);
	}

	public function testExtractReturnsEmptyWhenDbNotConnected(): void {
		$db = $this->createMock(IDatabase::class);
		$resolver = $this->createStub(IAgentConfigValueResolver::class);

		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(false);

		$db->expects($this->never())->method('multiQuery');
		$db->expects($this->never())->method('nonQuery');

		$r = new XrmEmbeddingQueueExtractorAgentResource($db, $resolver, 'x5');

		$out = $r->extract($this->makeContextStub());

		$this->assertSame([], $out);
	}

	public function testExtractReturnsEmptyWhenNoPendingJobsFound(): void {
		$db = $this->createMock(IDatabase::class);
		$resolver = $this->createStub(IAgentConfigValueResolver::class);

		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		$db->expects($this->once())->method('multiQuery')->willReturn([]);
		$db->expects($this->never())->method('nonQuery');

		$r = new XrmEmbeddingQueueExtractorAgentResource($db, $resolver, 'x6');

		$out = $r->extract($this->makeContextStub());

		$this->assertSame([], $out);
	}

	public function testExtractFailsUnsupportedJobTypeAndSkips(): void {
		$db = $this->createMock(IDatabase::class);
		$resolver = $this->createStub(IAgentConfigValueResolver::class);

		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		$db->method('escape')->willReturnCallback(function (string $s): string {
			return $s;
		});

		$db->expects($this->exactly(2))
			->method('multiQuery')
			->willReturnOnConsecutiveCalls(
				[['job_id' => 10]],
				[[
					'job_id' => 10,
					'job_type' => 'weird',
					'attempts' => 2,
					'collection_key' => 'xrm',
					'source_uuid_hex' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
					'source_version_hex' => 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'
				]]
			);

		// Claim update + markFailed (pending/error update) for unsupported job_type.
		$db->expects($this->exactly(2))->method('nonQuery');

		$r = new XrmEmbeddingQueueExtractorAgentResource($db, $resolver, 'x7');

		$out = $r->extract($this->makeContextStub());

		$this->assertSame([], $out);
	}

	public function testExtractBuildsDeleteItemAndAckMarksDoneAndSeenDeletedAt(): void {
		$db = $this->createMock(IDatabase::class);
		$resolver = $this->createStub(IAgentConfigValueResolver::class);

		$db->method('escape')->willReturnCallback(function (string $s): string {
			return $s;
		});

		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		$db->expects($this->exactly(2))
			->method('multiQuery')
			->willReturnOnConsecutiveCalls(
				[['job_id' => 11]],
				[[
					'job_id' => 11,
					'job_type' => 'delete',
					'attempts' => 0,
					'collection_key' => 'xrm',
					'source_uuid_hex' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
					'source_version_hex' => ''
				]]
			);

		// Claim update happens once.
		$db->expects($this->once())
			->method('nonQuery')
			->with($this->stringContains("SET state = 'running'"));

		$r = new XrmEmbeddingQueueExtractorAgentResource($db, $resolver, 'x8');

		$out = $r->extract($this->makeContextStub());

		$this->assertCount(1, $out);
		$this->assertInstanceOf(AgentContentItem::class, $out[0]);

		$item = $out[0];

		$this->assertTrue($item->isDelete());
		$this->assertSame('xrm', $item->collectionKey);
		$this->assertSame('11', $item->id);
		$this->assertSame('application/x-embedding-job-delete', $item->contentType);
		$this->assertSame('', $item->content);
		$this->assertSame(0, $item->size);

		$this->assertSame([
			'content_uuid' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
			'content_version' => null
		], $item->metadata);

		$expectedHash = hash('sha256', 'xrm:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:');
		$this->assertSame($expectedHash, $item->hash);

		// Ack: needs a connected DB again.
		$dbAck = $this->createMock(IDatabase::class);
		$dbAck->method('escape')->willReturnCallback(function (string $s): string {
			return $s;
		});

		$dbAck->expects($this->once())->method('connect');
		$dbAck->expects($this->once())->method('connected')->willReturn(true);

		// Mark done + mark seen deleted.
		$dbAck->expects($this->exactly(2))
			->method('nonQuery')
			->with($this->callback(function (string $sql): bool {
				return str_contains($sql, "UPDATE base3_embedding_job")
					|| str_contains($sql, "UPDATE base3_embedding_seen");
			}));

		$rAck = new XrmEmbeddingQueueExtractorAgentResource($dbAck, $resolver, 'x9');

		$rAck->ack($item);
	}

	public function testFailLoadsAttemptsAndMarksPendingOrErrorDependingOnRetryHintAndAttempts(): void {
		$resolver = $this->createStub(IAgentConfigValueResolver::class);

		$item = new AgentContentItem(
			action: 'upsert',
			collectionKey: 'xrm',
			id: '12',
			hash: 'h',
			contentType: 'application/json',
			content: [],
			isBinary: false,
			size: 1,
			metadata: []
		);

		// Case 1: retryHint=true and attempts below max -> state pending.
		$db1 = $this->createMock(IDatabase::class);
		$db1->method('escape')->willReturnCallback(function (string $s): string {
			return $s;
		});

		$db1->expects($this->once())->method('connect');
		$db1->expects($this->once())->method('connected')->willReturn(true);

		$db1->expects($this->once())
			->method('singleQuery')
			->with($this->stringContains('SELECT attempts'))
			->willReturn(['attempts' => 1]);

		$db1->expects($this->once())
			->method('nonQuery')
			->with($this->stringContains("SET state = 'pending'"));

		$r1 = new XrmEmbeddingQueueExtractorAgentResource($db1, $resolver, 'x10');
		$r1->fail($item, 'err', true);

		// Case 2: retryHint=false -> final error.
		$db2 = $this->createMock(IDatabase::class);
		$db2->method('escape')->willReturnCallback(function (string $s): string {
			return $s;
		});

		$db2->expects($this->once())->method('connect');
		$db2->expects($this->once())->method('connected')->willReturn(true);

		$db2->expects($this->once())
			->method('singleQuery')
			->willReturn(['attempts' => 1]);

		$db2->expects($this->once())
			->method('nonQuery')
			->with($this->stringContains("SET state = 'error'"));

		$r2 = new XrmEmbeddingQueueExtractorAgentResource($db2, $resolver, 'x11');
		$r2->fail($item, 'err', false);

		// Case 3: attempts >= max -> final error.
		$db3 = $this->createMock(IDatabase::class);
		$db3->method('escape')->willReturnCallback(function (string $s): string {
			return $s;
		});

		$db3->expects($this->once())->method('connect');
		$db3->expects($this->once())->method('connected')->willReturn(true);

		$db3->expects($this->once())
			->method('singleQuery')
			->willReturn(['attempts' => 5]);

		$db3->expects($this->once())
			->method('nonQuery')
			->with($this->stringContains("SET state = 'error'"));

		$r3 = new XrmEmbeddingQueueExtractorAgentResource($db3, $resolver, 'x12');
		$r3->fail($item, 'err', true);
	}
}
