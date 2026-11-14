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

namespace Base3\MissionBayXrm\Test;

use Base3\CoreFoundation\Api\IContainer;
use Base3\MissionBayXrm\MissionBayXrmPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Base3\MissionBayXrm\MissionBayXrmPlugin
 */
#[AllowMockObjectsWithoutExpectations]
final class MissionBayXrmPluginTest extends TestCase {

	public function testGetNameReturnsTechnicalName(): void {
		$this->assertSame('missionbayxrmplugin', MissionBayXrmPlugin::getName());
	}

	public function testInitRegistersOnlyCurrentPlugin(): void {
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

		$this->assertCount(1, $calls);

		$this->assertSame(MissionBayXrmPlugin::getName(), $calls[0]['name']);
		$this->assertSame(IContainer::SHARED, $calls[0]['flags']);
		$this->assertSame($plugin, $calls[0]['def']);
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
