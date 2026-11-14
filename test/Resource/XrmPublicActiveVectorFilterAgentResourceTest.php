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

use PHPUnit\Framework\TestCase;
use Base3\MissionBayXrm\Resource\XrmPublicActiveVectorFilterAgentResource;

/**
 * @covers \Base3\MissionBayXrm\Resource\XrmPublicActiveVectorFilterAgentResource
 */
class XrmPublicActiveVectorFilterAgentResourceTest extends TestCase {

	public function testGetName(): void {
		$this->assertSame(
			'xrmpublicactivevectorfilteragentresource',
			XrmPublicActiveVectorFilterAgentResource::getName()
		);
	}

	public function testGetDescription(): void {
		$r = new XrmPublicActiveVectorFilterAgentResource('x1');

		$this->assertSame(
			'Restricts vector retrieval to public, non-archived XRM entries.',
			$r->getDescription()
		);
	}

	public function testGetFilterSpecReturnsMustPublicAndArchiveConstraints(): void {
		$r = new XrmPublicActiveVectorFilterAgentResource('x2');

		$spec = $r->getFilterSpec();

		$this->assertSame([
			'must' => [
				'public' => 1,
				'archive' => 0
			]
		], $spec);
	}
}
