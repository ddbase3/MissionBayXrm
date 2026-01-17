<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Resource\XrmProductMetaParserAgentResource;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;

/**
 * @covers \MissionBayXrm\Resource\XrmProductMetaParserAgentResource
 */
class XrmProductMetaParserAgentResourceTest extends TestCase {

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
		$this->assertSame('xrmproductmetaparseragentresource', XrmProductMetaParserAgentResource::getName());
	}

	public function testGetDescription(): void {
		$p = new XrmProductMetaParserAgentResource('x1');

		$this->assertSame(
			'Adds type-specific metadata for XRM entries with type_alias=product (code, weight).',
			$p->getDescription()
		);
	}

	public function testGetPriority(): void {
		$p = new XrmProductMetaParserAgentResource('x2');
		$this->assertSame(10, $p->getPriority());
	}

	public function testSupportsReturnsFalseForNonAgentContentItem(): void {
		$p = new XrmProductMetaParserAgentResource('x3');
		$this->assertFalse($p->supports(['x' => 1]));
	}

	public function testSupportsReturnsFalseWhenContentIsNotArrayOrObject(): void {
		$p = new XrmProductMetaParserAgentResource('x4');

		$item = $this->makeItem('not-structured', ['type_alias' => 'product']);

		$this->assertFalse($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromStructuredContent(): void {
		$p = new XrmProductMetaParserAgentResource('x5');

		$item = $this->makeItem([
			'type' => ['alias' => 'product'],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromMetadataFallback(): void {
		$p = new XrmProductMetaParserAgentResource('x6');

		$item = $this->makeItem([
			'type' => [],
			'payload' => []
		], [
			'type_alias' => 'product'
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testParseThrowsForNonAgentContentItem(): void {
		$p = new XrmProductMetaParserAgentResource('x7');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('XrmProductMetaParser expects AgentContentItem.');

		$p->parse(['x' => 1]);
	}

	public function testParseAddsCodeAndWeightAndMergesProductMetadataParserWins(): void {
		$p = new XrmProductMetaParserAgentResource('x8');

		$item = $this->makeItem([
			'type' => ['alias' => 'product'],
			'payload' => [
				'code' => '  P-001  ',
				'weight' => '42',
				'keep' => 'ok'
			]
		], [
			'product' => ['code' => 'OLD', 'other' => 'x']
		]);

		$out = $p->parse($item);

		$this->assertInstanceOf(AgentParsedContent::class, $out);
		$this->assertSame('', $out->text);
		$this->assertSame([], $out->attachments);

		$this->assertSame([
			'product' => [
				'code' => 'P-001',
				'other' => 'x',
				'weight' => 42
			]
		], $out->metadata);

		$this->assertIsArray($out->structured);
		$this->assertSame('ok', $out->structured['payload']['keep']);
	}

	public function testParseDoesNotCreateProductMetadataWhenValuesAreEmptyOrInvalid(): void {
		$p = new XrmProductMetaParserAgentResource('x9');

		$item = $this->makeItem([
			'type' => ['alias' => 'product'],
			'payload' => [
				'code' => '   ',
				'weight' => '42.5'
			]
		], [
			'keep_meta' => 'x'
		]);

		$out = $p->parse($item);

		$this->assertSame(['keep_meta' => 'x'], $out->metadata);
	}

	public function testParseWeightConversionRules(): void {
		$p = new XrmProductMetaParserAgentResource('x10');

		$item1 = $this->makeItem([
			'type' => ['alias' => 'product'],
			'payload' => ['weight' => 7]
		]);

		$out1 = $p->parse($item1);
		$this->assertSame(['product' => ['weight' => 7]], $out1->metadata);

		$item2 = $this->makeItem([
			'type' => ['alias' => 'product'],
			'payload' => ['weight' => true]
		]);

		$out2 = $p->parse($item2);
		$this->assertSame(['product' => ['weight' => 1]], $out2->metadata);

		$item3 = $this->makeItem([
			'type' => ['alias' => 'product'],
			'payload' => ['weight' => 2.4]
		]);

		$out3 = $p->parse($item3);
		$this->assertSame(['product' => ['weight' => 2]], $out3->metadata);

		$item4 = $this->makeItem([
			'type' => ['alias' => 'product'],
			'payload' => ['weight' => 2.6]
		]);

		$out4 = $p->parse($item4);
		$this->assertSame(['product' => ['weight' => 3]], $out4->metadata);

		$item5 = $this->makeItem([
			'type' => ['alias' => 'product'],
			'payload' => ['weight' => ' -12 ']
		]);

		$out5 = $p->parse($item5);
		$this->assertSame(['product' => ['weight' => -12]], $out5->metadata);
	}

	public function testParseAcceptsPayloadAsObjectAndContentAsObject(): void {
		$p = new XrmProductMetaParserAgentResource('x11');

		$content = (object)[
			'type' => (object)['alias' => 'product'],
			'payload' => (object)[
				'code' => 123,
				'weight' => '9'
			]
		];

		$item = $this->makeItem($content);

		$out = $p->parse($item);

		$this->assertSame([
			'product' => [
				'code' => '123',
				'weight' => 9
			]
		], $out->metadata);
	}
}
