<?php declare(strict_types=1);

namespace Test\MissionBayXrm;

use Base3\Api\IContainer;
use MissionBay\Api\IAgentRagPayloadNormalizer;
use MissionBayXrm\Agent\XrmAgentRagPayloadNormalizer;
use MissionBayXrm\MissionBayXrmPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MissionBayXrm\MissionBayXrmPlugin
 */
#[AllowMockObjectsWithoutExpectations]
final class MissionBayXrmPluginTest extends TestCase {

	public function testGetNameReturnsTechnicalName(): void {
		$this->assertSame('missionbayxrmplugin', MissionBayXrmPlugin::getName());
	}

	public function testInitRegistersPluginAndNormalizer(): void {
		$container = $this->createMock(IContainer::class);

		$calls = [];

		$container->method('set')
			->willReturnCallback(function (string $name, $classDefinition, $flags) use (&$calls, $container) {
				$calls[] = [
					'name' => $name,
					'def' => $classDefinition,
					'flags' => $flags
				];
				return $container;
			});

		$plugin = new MissionBayXrmPlugin($container);
		$plugin->init();

		$this->assertCount(2, $calls);

		$this->assertSame(MissionBayXrmPlugin::getName(), $calls[0]['name']);
		$this->assertSame(IContainer::SHARED, $calls[0]['flags']);
		$this->assertSame($plugin, $calls[0]['def']);

		$this->assertSame(IAgentRagPayloadNormalizer::class, $calls[1]['name']);
		$this->assertSame(IContainer::SHARED, $calls[1]['flags']);
		$this->assertIsCallable($calls[1]['def']);
	}

	public function testInitFactoryCreatesXrmAgentRagPayloadNormalizer(): void {
		$container = $this->createMock(IContainer::class);

		$capturedFactory = null;

		$container->method('set')
			->willReturnCallback(function (string $name, $classDefinition, $flags) use (&$capturedFactory, $container) {
				if ($name === IAgentRagPayloadNormalizer::class) {
					$capturedFactory = $classDefinition;
				}
				return $container;
			});

		$plugin = new MissionBayXrmPlugin($container);
		$plugin->init();

		$this->assertIsCallable($capturedFactory);

		$instance = $capturedFactory();
		$this->assertInstanceOf(XrmAgentRagPayloadNormalizer::class, $instance);
	}

	public function testCheckDependenciesReturnsOkWhenMissionBayPluginInstalled(): void {
		$container = $this->createMock(IContainer::class);
		$container->method('get')->with('missionbayplugin')->willReturn(new \stdClass());

		$plugin = new MissionBayXrmPlugin($container);

		$res = $plugin->checkDependencies();

		$this->assertIsArray($res);
		$this->assertArrayHasKey('missionbayplugin_installed', $res);
		$this->assertSame('Ok', $res['missionbayplugin_installed']);
	}

	public function testCheckDependenciesReturnsMessageWhenMissionBayPluginMissing(): void {
		$container = $this->createMock(IContainer::class);
		$container->method('get')->with('missionbayplugin')->willReturn(null);

		$plugin = new MissionBayXrmPlugin($container);

		$res = $plugin->checkDependencies();

		$this->assertIsArray($res);
		$this->assertArrayHasKey('missionbayplugin_installed', $res);
		$this->assertSame('missionbayplugin not installed', $res['missionbayplugin_installed']);
	}
}
