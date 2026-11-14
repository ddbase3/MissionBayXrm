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

use Base3\AssistantFoundation\Api\IAgentContext;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\MissionBay\Api\IAgentContextFactory;
use Base3\MissionBay\Api\IAgentFlow;
use Base3\MissionBay\Api\IAgentFlowFactory;
use Base3\MissionBayXrm\Job\XrmEmbeddingWorkerJob;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Base3\MissionBayXrm\Job\XrmEmbeddingWorkerJob
 */
final class XrmEmbeddingWorkerJobBoundaryTest extends TestCase {

	public function testEmptyFlowOutputReportsZeroCountersWithoutAnError(): void {
		$this->withFlowConfig('{"nodes":[]}', function(): void {
			$this->assertSame(
				'Worker done - items: 0, failed: 0, chunks: 0, vectors: 0',
				$this->worker([])->go()
			);
		});
	}

	public function testNonArrayStatisticsDoNotAppearInWorkerMessage(): void {
		$this->withFlowConfig('{"nodes":[]}', function(): void {
			$this->assertSame(
				'Worker done - error: partial result',
				$this->worker(['embedding' => ['stats' => 'unavailable', 'error' => 'partial result']])->go()
			);
		});
	}

	public function testNonArrayStatisticsAndFalsyErrorDoNotAddDetails(): void {
		$this->withFlowConfig('{"nodes":[]}', function(): void {
			$this->assertSame('Worker done', $this->worker(['embedding' => ['stats' => false, 'error' => '']])->go());
		});
	}

	public function testExplicitNullStatisticsUseDefaultZeroCounters(): void {
		$this->withFlowConfig('{"nodes":[]}', function(): void {
			$this->assertSame(
				'Worker done - items: 0, failed: 0, chunks: 0, vectors: 0',
				$this->worker(['embedding' => ['stats' => null, 'error' => null]])->go()
			);
		});
	}

	public function testSparseStatisticsAreConvertedToIntegerCounters(): void {
		$this->withFlowConfig('{"nodes":[]}', function(): void {
			$this->assertSame(
				'Worker done - items: 7, failed: 0, chunks: 3, vectors: 0',
				$this->worker(['embedding' => ['stats' => ['num_items_done' => '7', 'num_chunks' => 3.9], 'error' => 0]])->go()
			);
		});
	}

	public function testValidJsonScalarsAndEmptyFlowConfigurationAreRejected(): void {
		foreach (['null', 'true', '17', '"not a flow"', '[]', '{}', ''] as $json) {
			$this->withFlowConfig($json, function() use ($json): void {
				$db = $this->createStub(IDatabase::class);
				$db->method('connected')->willReturn(true);
				$contextFactory = $this->createMock(IAgentContextFactory::class);
				$contextFactory->expects($this->never())->method('createContext');
				$flowFactory = $this->createMock(IAgentFlowFactory::class);
				$flowFactory->expects($this->never())->method('createFromArray');
				$this->assertSame('Invalid embedding flow JSON', (new XrmEmbeddingWorkerJob($db, $contextFactory, $flowFactory))->go(), $json);
			});
		}
	}

	public function testValidFlowConfigurationIsPassedUnchangedToOwningFactory(): void {
		$config = ['nodes' => [['id' => 'source', 'type' => 'resource']], 'connections' => []];
		$json = json_encode($config);
		$this->assertNotEmpty($json);
		$this->withFlowConfig($json, function() use ($config): void {
			$db = $this->createStub(IDatabase::class);
			$db->method('connected')->willReturn(true);
			$context = $this->createStub(IAgentContext::class);
			$contextFactory = $this->createStub(IAgentContextFactory::class);
			$contextFactory->method('createContext')->willReturn($context);
			$flow = $this->createStub(IAgentFlow::class);
			$flow->method('run')->willReturn([]);
			$flowFactory = $this->createMock(IAgentFlowFactory::class);
			$flowFactory->expects($this->once())->method('createFromArray')->with('strictflow', $config, $context)->willReturn($flow);
			$this->assertSame('Worker done - items: 0, failed: 0, chunks: 0, vectors: 0', (new XrmEmbeddingWorkerJob($db, $contextFactory, $flowFactory))->go());
		});
	}

	private function worker(array $output): XrmEmbeddingWorkerJob {
		$db = $this->createStub(IDatabase::class);
		$db->method('connected')->willReturn(true);
		$contextFactory = $this->createStub(IAgentContextFactory::class);
		$contextFactory->method('createContext')->willReturn($this->createStub(IAgentContext::class));
		$flow = $this->createStub(IAgentFlow::class);
		$flow->method('run')->willReturn($output);
		$flowFactory = $this->createStub(IAgentFlowFactory::class);
		$flowFactory->method('createFromArray')->willReturn($flow);
		return new XrmEmbeddingWorkerJob($db, $contextFactory, $flowFactory);
	}

	private function withFlowConfig(string $json, callable $test): void {
		$file = \dirname(__DIR__, 2) . '/local/Ai/embeddingflow.json';
		$directory = dirname($file);
		$existing = is_file($file);
		$original = $existing ? file_get_contents($file) : null;
		if ($original === false) {
			$this->fail('Existing embedding flow configuration cannot be read.');
		}
		$createdDirectory = !is_dir($directory);
		if ($createdDirectory && !mkdir($directory, 0777, true) && !is_dir($directory)) {
			$this->fail('Cannot create embedding flow test directory.');
		}
		try {
			if (file_put_contents($file, $json) === false) {
				$this->fail('Cannot write embedding flow test configuration.');
			}
			$test();
		} finally {
			if ($existing) {
				file_put_contents($file, $original);
			} else {
				@unlink($file);
			}
			if ($createdDirectory) {
				@rmdir($directory);
				@rmdir(dirname($directory));
			}
		}
	}
}
