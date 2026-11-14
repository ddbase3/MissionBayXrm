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

namespace Base3\MissionBayXrm\Test\Job;

use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\AssistantFoundation\Api\IAgentContext;
use Base3\MissionBay\Api\IAgentContextFactory;
use Base3\MissionBay\Api\IAgentFlow;
use Base3\MissionBay\Api\IAgentFlowFactory;
use Base3\MissionBayXrm\Job\XrmEmbeddingWorkerJob;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Base3\MissionBayXrm\Job\XrmEmbeddingWorkerJob
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
