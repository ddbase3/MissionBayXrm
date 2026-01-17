<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Resource\XrmResourceMetaParserAgentResource;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;

/**
 * @covers \MissionBayXrm\Resource\XrmResourceMetaParserAgentResource
 */
class XrmResourceMetaParserAgentResourceTest extends TestCase {

	private function makeItem(array|object|string $content, array $metadata = []): AgentContentItem {
		return new AgentContentItem(
			action: 'upsert',
			collectionKey: 'xrm',
			id: 'i1',
			hash: 'h1',
			contentType: 'application/json',
			content: $content,
			isBinary: false,
			size: 1,
			metadata: $metadata
		);
	}

	public function testGetName(): void {
		$this->assertSame('xrmresourcemetaparseragentresource', XrmResourceMetaParserAgentResource::getName());
	}

	public function testGetDescription(): void {
		$p = new XrmResourceMetaParserAgentResource('x1');

		$this->assertSame(
			'Adds type-specific metadata for XRM entries with type_alias=resource (quota, used).',
			$p->getDescription()
		);
	}

	public function testGetPriority(): void {
		$p = new XrmResourceMetaParserAgentResource('x2');
		$this->assertSame(10, $p->getPriority());
	}

	public function testSupportsReturnsFalseForNonAgentContentItem(): void {
		$p = new XrmResourceMetaParserAgentResource('x3');
		$this->assertFalse($p->supports(['x' => 1]));
	}

	public function testSupportsReturnsFalseWhenContentIsNotArrayOrObject(): void {
		$p = new XrmResourceMetaParserAgentResource('x4');

		$item = $this->makeItem('not-structured', ['type_alias' => 'resource']);

		$this->assertFalse($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromStructuredContent(): void {
		$p = new XrmResourceMetaParserAgentResource('x5');

		$item = $this->makeItem([
			'type' => ['alias' => 'resource'],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromMetadataFallback(): void {
		$p = new XrmResourceMetaParserAgentResource('x6');

		$item = $this->makeItem([
			'type' => [],
			'payload' => []
		], [
			'type_alias' => 'resource'
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testParseThrowsForNonAgentContentItem(): void {
		$p = new XrmResourceMetaParserAgentResource('x7');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('XrmResourceMetaParser expects AgentContentItem.');

		$p->parse(['x' => 1]);
	}

	public function testParseAddsQuotaAndUsedAndMergesResourceMetadataParserWins(): void {
		$p = new XrmResourceMetaParserAgentResource('x8');

		$item = $this->makeItem([
			'type' => ['alias' => 'resource'],
			'payload' => [
				'quota' => ' 100 ',
				'used' => 50,
				'keep' => 'ok'
			]
		], [
			'resource' => ['quota' => 1, 'other' => 'x']
		]);

		$out = $p->parse($item);

		$this->assertInstanceOf(AgentParsedContent::class, $out);
		$this->assertSame('', $out->text);
		$this->assertSame([], $out->attachments);

		$this->assertSame([
			'resource' => [
				'quota' => 100,
				'other' => 'x',
				'used' => 50
			]
		], $out->metadata);

		$this->assertIsArray($out->structured);
		$this->assertSame('ok', $out->structured['payload']['keep']);
	}

	public function testParseDoesNotCreateResourceMetadataWhenValuesAreInvalidOrEmpty(): void {
		$p = new XrmResourceMetaParserAgentResource('x9');

		$item = $this->makeItem([
			'type' => ['alias' => 'resource'],
			'payload' => [
				'quota' => '',
				'used' => '1.5'
			]
		], [
			'keep_meta' => 'x'
		]);

		$out = $p->parse($item);

		$this->assertSame(['keep_meta' => 'x'], $out->metadata);
	}

	public function testParseIntConversionRules(): void {
		$p = new XrmResourceMetaParserAgentResource('x10');

		$item1 = $this->makeItem([
			'type' => ['alias' => 'resource'],
			'payload' => ['quota' => true]
		]);

		$out1 = $p->parse($item1);
		$this->assertSame(['resource' => ['quota' => 1]], $out1->metadata);

		$item2 = $this->makeItem([
			'type' => ['alias' => 'resource'],
			'payload' => ['used' => 2.4]
		]);

		$out2 = $p->parse($item2);
		$this->assertSame(['resource' => ['used' => 2]], $out2->metadata);

		$item3 = $this->makeItem([
			'type' => ['alias' => 'resource'],
			'payload' => ['used' => 2.6]
		]);

		$out3 = $p->parse($item3);
		$this->assertSame(['resource' => ['used' => 3]], $out3->metadata);

		$item4 = $this->makeItem([
			'type' => ['alias' => 'resource'],
			'payload' => ['quota' => ' -12 ']
		]);

		$out4 = $p->parse($item4);
		$this->assertSame(['resource' => ['quota' => -12]], $out4->metadata);
	}

	public function testParseAcceptsPayloadAsObjectAndContentAsObject(): void {
		$p = new XrmResourceMetaParserAgentResource('x11');

		$content = (object)[
			'type' => (object)['alias' => 'resource'],
			'payload' => (object)[
				'quota' => '9',
				'used' => false
			]
		];

		$item = $this->makeItem($content);

		$out = $p->parse($item);

		$this->assertSame([
			'resource' => [
				'quota' => 9,
				'used' => 0
			]
		], $out->metadata);
	}
}
