<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Resource\XrmAddressMetaParserAgentResource;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;

/**
 * @covers \MissionBayXrm\Resource\XrmAddressMetaParserAgentResource
 */
class XrmAddressMetaParserAgentResourceTest extends TestCase {

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
		$this->assertSame('xrmaddressmetaparseragentresource', XrmAddressMetaParserAgentResource::getName());
	}

	public function testGetDescription(): void {
		$p = new XrmAddressMetaParserAgentResource('x1');

		$this->assertSame(
			'Adds address-related metadata (lat, lng, city, country) for XRM entries with type_alias=address.',
			$p->getDescription()
		);
	}

	public function testGetPriority(): void {
		$p = new XrmAddressMetaParserAgentResource('x2');
		$this->assertSame(10, $p->getPriority());
	}

	public function testSupportsReturnsFalseForNonAgentContentItem(): void {
		$p = new XrmAddressMetaParserAgentResource('x3');
		$this->assertFalse($p->supports(['x' => 1]));
	}

	public function testSupportsReturnsFalseWhenContentIsNotArrayOrObject(): void {
		$p = new XrmAddressMetaParserAgentResource('x4');

		$item = $this->makeItem('not-structured', ['type_alias' => 'address']);

		$this->assertFalse($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromStructuredContent(): void {
		$p = new XrmAddressMetaParserAgentResource('x5');

		$item = $this->makeItem([
			'type' => ['alias' => 'address'],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromMetadataFallback(): void {
		$p = new XrmAddressMetaParserAgentResource('x6');

		$item = $this->makeItem([
			'type' => [],
			'payload' => []
		], [
			'type_alias' => 'address'
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsIsCaseInsensitiveAndTrimsAlias(): void {
		$p = new XrmAddressMetaParserAgentResource('x7');

		$item = $this->makeItem([
			'type' => ['alias' => '  AdDrEsS  '],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testParseThrowsForNonAgentContentItem(): void {
		$p = new XrmAddressMetaParserAgentResource('x8');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('XrmAddressMetaParser expects AgentContentItem.');

		$p->parse(['x' => 1]);
	}

	public function testParseAddsLatLngCityCountryWhenValidAndMergesAddressMetadataParserWins(): void {
		$p = new XrmAddressMetaParserAgentResource('x9');

		$item = $this->makeItem([
			'type' => ['alias' => 'address'],
			'payload' => [
				'lat' => '52.5200',
				'lng' => '13.4050',
				'city' => '  Berlin ',
				'country' => ' DE ',
				'keep' => 'ok'
			]
		], [
			'address' => ['city' => 'OldCity', 'zip' => '10115']
		]);

		$out = $p->parse($item);

		$this->assertInstanceOf(AgentParsedContent::class, $out);
		$this->assertSame('', $out->text);
		$this->assertSame([], $out->attachments);

		$this->assertSame([
			'address' => [
				'city' => 'Berlin',
				'zip' => '10115',
				'lat' => 52.52,
				'lng' => 13.405,
				'country' => 'DE'
			]
		], $out->metadata);

		$this->assertIsArray($out->structured);
		$this->assertSame('ok', $out->structured['payload']['keep']);
	}

	public function testParseDoesNotAddLatLngWhenOutOfRangeOrNonNumeric(): void {
		$p = new XrmAddressMetaParserAgentResource('x10');

		$item = $this->makeItem([
			'type' => ['alias' => 'address'],
			'payload' => [
				'lat' => 200,
				'lng' => 13,
				'city' => 'Berlin'
			]
		]);

		$out = $p->parse($item);
		$this->assertSame(['address' => ['city' => 'Berlin']], $out->metadata);

		$item2 = $this->makeItem([
			'type' => ['alias' => 'address'],
			'payload' => [
				'lat' => 'x',
				'lng' => 'y',
				'city' => 'Berlin'
			]
		]);

		$out2 = $p->parse($item2);
		$this->assertSame(['address' => ['city' => 'Berlin']], $out2->metadata);
	}

	public function testParseDoesNotAddCityOrCountryWhenEmptyAfterTrim(): void {
		$p = new XrmAddressMetaParserAgentResource('x11');

		$item = $this->makeItem([
			'type' => ['alias' => 'address'],
			'payload' => [
				'lat' => 10,
				'lng' => 10,
				'city' => '   ',
				'country' => ''
			]
		], [
			'address' => ['keep' => 'x']
		]);

		$out = $p->parse($item);

		$this->assertSame([
			'address' => [
				'keep' => 'x',
				'lat' => 10.0,
				'lng' => 10.0
			]
		], $out->metadata);
	}

	public function testParseDoesNotCreateAddressMetadataWhenNoValidValuesExist(): void {
		$p = new XrmAddressMetaParserAgentResource('x12');

		$item = $this->makeItem([
			'type' => ['alias' => 'address'],
			'payload' => [
				'lat' => 999,
				'lng' => 999,
				'city' => '   ',
				'country' => '   '
			]
		], [
			'keep_meta' => 'x'
		]);

		$out = $p->parse($item);

		$this->assertSame(['keep_meta' => 'x'], $out->metadata);
	}

	public function testParseAcceptsPayloadAsObjectAndContentAsObject(): void {
		$p = new XrmAddressMetaParserAgentResource('x13');

		$content = (object)[
			'type' => (object)['alias' => 'address'],
			'payload' => (object)[
				'lat' => 40.0,
				'lng' => -73.0,
				'city' => ' New York ',
				'country' => ' US '
			]
		];

		$item = $this->makeItem($content);

		$out = $p->parse($item);

		$this->assertSame([
			'address' => [
				'lat' => 40.0,
				'lng' => -73.0,
				'city' => 'New York',
				'country' => 'US'
			]
		], $out->metadata);
	}
}
