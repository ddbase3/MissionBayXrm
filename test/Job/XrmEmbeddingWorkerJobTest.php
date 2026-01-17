<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Job;

use Base3\Database\Api\IDatabase;
use MissionBay\Api\IAgentContext;
use MissionBay\Api\IAgentContextFactory;
use MissionBay\Api\IAgentFlow;
use MissionBay\Api\IAgentFlowFactory;
use MissionBayXrm\Job\XrmEmbeddingWorkerJob;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MissionBayXrm\Job\XrmEmbeddingWorkerJob
 */
#[AllowMockObjectsWithoutExpectations]
final class XrmEmbeddingWorkerJobTest extends TestCase {

	private string $flowFile;

	protected function setUp(): void {
		parent::setUp();

		// Must match XrmEmbeddingWorkerJob::FLOW_FILE exactly.
		$this->flowFile = \dirname(__DIR__, 2) . '/local/Ai/embeddingflow.json';
	}

	protected function tearDown(): void {
		$this->deleteFlowFile();
		parent::tearDown();
	}

	public function testGoReturnsDbNotConnectedWhenConnectFails(): void {
		$this->deleteFlowFile();

		$db = $this->createMock(IDatabase::class);
		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(false);

		$contextFactory = $this->createMock(IAgentContextFactory::class);
		$contextFactory->expects($this->never())->method('createContext');

		$flowFactory = $this->createMock(IAgentFlowFactory::class);
		$flowFactory->expects($this->never())->method('createFromArray');

		$job = new XrmEmbeddingWorkerJob($db, $contextFactory, $flowFactory);

		$this->assertSame('DB not connected', $job->go());
	}

	public function testGoReturnsInvalidEmbeddingFlowJsonWhenConfigMissing(): void {
		$this->deleteFlowFile();

		$db = $this->createMock(IDatabase::class);
		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		$contextFactory = $this->createMock(IAgentContextFactory::class);
		$contextFactory->expects($this->never())->method('createContext');

		$flowFactory = $this->createMock(IAgentFlowFactory::class);
		$flowFactory->expects($this->never())->method('createFromArray');

		$job = new XrmEmbeddingWorkerJob($db, $contextFactory, $flowFactory);

		$this->assertSame('Invalid embedding flow JSON', $job->go());
	}

	public function testGoReturnsInvalidEmbeddingFlowJsonWhenConfigIsInvalidJson(): void {
		$this->writeFlowFileRaw('{not valid json');

		$db = $this->createMock(IDatabase::class);
		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		$contextFactory = $this->createMock(IAgentContextFactory::class);
		$contextFactory->expects($this->never())->method('createContext');

		$flowFactory = $this->createMock(IAgentFlowFactory::class);
		$flowFactory->expects($this->never())->method('createFromArray');

		$job = new XrmEmbeddingWorkerJob($db, $contextFactory, $flowFactory);

		$this->assertSame('Invalid embedding flow JSON', $job->go());
	}

	public function testGoReturnsFlowExecutionFailedWhenRunThrows(): void {
		$this->writeFlowFile(['type' => 'flow', 'nodes' => []]);

		$db = $this->createMock(IDatabase::class);
		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		$ctx = $this->createMock(IAgentContext::class);

		$contextFactory = $this->createMock(IAgentContextFactory::class);
		$contextFactory->expects($this->once())->method('createContext')->willReturn($ctx);

		$flow = $this->createMock(IAgentFlow::class);
		$flow->expects($this->once())->method('run')->with([])->willThrowException(new \RuntimeException('boom'));

		$flowFactory = $this->createMock(IAgentFlowFactory::class);
		$flowFactory->expects($this->once())
			->method('createFromArray')
			->with('strictflow', $this->isArray(), $ctx)
			->willReturn($flow);

		$job = new XrmEmbeddingWorkerJob($db, $contextFactory, $flowFactory);

		$this->assertSame('Flow execution failed: boom', $job->go());
	}

	public function testGoBuildsStatsMessageWithoutError(): void {
		$this->writeFlowFile(['type' => 'flow', 'nodes' => []]);

		$db = $this->createMock(IDatabase::class);
		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		$ctx = $this->createMock(IAgentContext::class);

		$contextFactory = $this->createMock(IAgentContextFactory::class);
		$contextFactory->expects($this->once())->method('createContext')->willReturn($ctx);

		$flow = $this->createMock(IAgentFlow::class);
		$flow->expects($this->once())->method('run')->with([])->willReturn([
			'embedding' => [
				'stats' => [
					'num_items_done' => 3,
					'num_items_failed' => 1,
					'num_chunks' => 12,
					'num_vectors' => 12
				],
				'error' => null
			]
		]);

		$flowFactory = $this->createMock(IAgentFlowFactory::class);
		$flowFactory->expects($this->once())
			->method('createFromArray')
			->with('strictflow', $this->isArray(), $ctx)
			->willReturn($flow);

		$job = new XrmEmbeddingWorkerJob($db, $contextFactory, $flowFactory);

		$msg = $job->go();

		$this->assertStringContainsString('Worker done', $msg);
		$this->assertStringContainsString('items: 3', $msg);
		$this->assertStringContainsString('failed: 1', $msg);
		$this->assertStringContainsString('chunks: 12', $msg);
		$this->assertStringContainsString('vectors: 12', $msg);
		$this->assertStringNotContainsString('error:', $msg);
	}

	public function testGoAppendsErrorMessageWhenEmbeddingErrorPresent(): void {
		$this->writeFlowFile(['type' => 'flow', 'nodes' => []]);

		$db = $this->createMock(IDatabase::class);
		$db->expects($this->once())->method('connect');
		$db->expects($this->once())->method('connected')->willReturn(true);

		$ctx = $this->createMock(IAgentContext::class);

		$contextFactory = $this->createMock(IAgentContextFactory::class);
		$contextFactory->expects($this->once())->method('createContext')->willReturn($ctx);

		$flow = $this->createMock(IAgentFlow::class);
		$flow->expects($this->once())->method('run')->with([])->willReturn([
			'embedding' => [
				'stats' => [
					'num_items_done' => 0,
					'num_items_failed' => 2,
					'num_chunks' => 0,
					'num_vectors' => 0
				],
				'error' => 'some failure'
			]
		]);

		$flowFactory = $this->createMock(IAgentFlowFactory::class);
		$flowFactory->expects($this->once())
			->method('createFromArray')
			->with('strictflow', $this->isArray(), $ctx)
			->willReturn($flow);

		$job = new XrmEmbeddingWorkerJob($db, $contextFactory, $flowFactory);

		$msg = $job->go();

		$this->assertStringContainsString('Worker done', $msg);
		$this->assertStringContainsString('items: 0', $msg);
		$this->assertStringContainsString('failed: 2', $msg);
		$this->assertStringContainsString('error: some failure', $msg);
	}

	// ---------------------------------------------------------
	// File helpers (real filesystem, deterministic path)
	// ---------------------------------------------------------

	private function ensureFlowDir(): void {
		$dir = \dirname($this->flowFile);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
	}

	private function writeFlowFile(array $cfg): void {
		$this->ensureFlowDir();

		$json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($json)) {
			$this->fail('Failed to encode flow config JSON for test.');
		}

		file_put_contents($this->flowFile, $json);
	}

	private function writeFlowFileRaw(string $raw): void {
		$this->ensureFlowDir();
		file_put_contents($this->flowFile, $raw);
	}

	private function deleteFlowFile(): void {
		if (is_file($this->flowFile)) {
			@unlink($this->flowFile);
		}
	}
}
