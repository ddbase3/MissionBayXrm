<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Resource\XrmPublicActiveVectorFilterAgentResource;

/**
 * @covers \MissionBayXrm\Resource\XrmPublicActiveVectorFilterAgentResource
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
