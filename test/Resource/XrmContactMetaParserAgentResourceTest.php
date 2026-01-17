<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Resource\XrmContactMetaParserAgentResource;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;

/**
 * @covers \MissionBayXrm\Resource\XrmContactMetaParserAgentResource
 */
class XrmContactMetaParserAgentResourceTest extends TestCase {

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
		$this->assertSame('xrmcontactmetaparseragentresource', XrmContactMetaParserAgentResource::getName());
	}

	public function testGetDescription(): void {
		$p = new XrmContactMetaParserAgentResource('x1');

		$this->assertSame(
			'Adds type-specific metadata for XRM entries with type_alias=contact (firstname, lastname, dateofbirth, birth_ts).',
			$p->getDescription()
		);
	}

	public function testGetPriority(): void {
		$p = new XrmContactMetaParserAgentResource('x2');
		$this->assertSame(10, $p->getPriority());
	}

	public function testSupportsReturnsFalseForNonAgentContentItem(): void {
		$p = new XrmContactMetaParserAgentResource('x3');
		$this->assertFalse($p->supports(['x' => 1]));
	}

	public function testSupportsReturnsFalseWhenContentIsNotArrayOrObject(): void {
		$p = new XrmContactMetaParserAgentResource('x4');

		$item = $this->makeItem('not-structured', ['type_alias' => 'contact']);

		$this->assertFalse($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromStructuredContent(): void {
		$p = new XrmContactMetaParserAgentResource('x5');

		$item = $this->makeItem([
			'type' => ['alias' => 'contact'],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromMetadataFallback(): void {
		$p = new XrmContactMetaParserAgentResource('x6');

		$item = $this->makeItem([
			'type' => [],
			'payload' => []
		], [
			'type_alias' => 'contact'
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsIsCaseInsensitiveAndTrimsAlias(): void {
		$p = new XrmContactMetaParserAgentResource('x7');

		$item = $this->makeItem([
			'type' => ['alias' => '  CoNtAcT  '],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testParseThrowsForNonAgentContentItem(): void {
		$p = new XrmContactMetaParserAgentResource('x8');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('XrmContactMetaParser expects AgentContentItem.');

		$p->parse(['x' => 1]);
	}

	public function testParseAddsFirstnameLastnameAndNormalizesDobAndAddsBirthTs(): void {
		$p = new XrmContactMetaParserAgentResource('x9');

		$item = $this->makeItem([
			'type' => ['alias' => 'contact'],
			'payload' => [
				'firstname' => '  Ada ',
				'lastname' => ' Lovelace  ',
				'dateofbirth' => '1980-02-03'
			]
		], [
			'contact' => ['firstname' => 'Old', 'keep' => 'x']
		]);

		$out = $p->parse($item);

		$this->assertInstanceOf(AgentParsedContent::class, $out);
		$this->assertSame('', $out->text);
		$this->assertSame([], $out->attachments);

		$this->assertArrayHasKey('contact', $out->metadata);

		$contact = $out->metadata['contact'];

		$this->assertSame('Ada', $contact['firstname']);
		$this->assertSame('Lovelace', $contact['lastname']);
		$this->assertSame('1980-02-03', $contact['dateofbirth']);

		// birth_ts should be a valid integer timestamp in UTC at midnight.
		$expected = (new \DateTimeImmutable('1980-02-03', new \DateTimeZone('UTC')))->setTime(0, 0)->getTimestamp();
		$this->assertSame($expected, $contact['birth_ts']);

		// Existing keys should remain.
		$this->assertSame('x', $contact['keep']);
	}

	public function testParseOmitsDobAndBirthTsWhenDobIsInvalid(): void {
		$p = new XrmContactMetaParserAgentResource('x10');

		$item = $this->makeItem([
			'type' => ['alias' => 'contact'],
			'payload' => [
				'firstname' => 'Ada',
				'dateofbirth' => '03.02.1980'
			]
		]);

		$out = $p->parse($item);

		$this->assertSame([
			'contact' => [
				'firstname' => 'Ada'
			]
		], $out->metadata);
	}

	public function testParseOmitsDobWhenDateDoesNotExist(): void {
		$p = new XrmContactMetaParserAgentResource('x11');

		$item = $this->makeItem([
			'type' => ['alias' => 'contact'],
			'payload' => [
				'dateofbirth' => '2020-02-31'
			]
		]);

		$out = $p->parse($item);

		$this->assertSame([], $out->metadata);
	}

	public function testParseDoesNotCreateContactMetadataWhenAllExtractedValuesAreEmpty(): void {
		$p = new XrmContactMetaParserAgentResource('x12');

		$item = $this->makeItem([
			'type' => ['alias' => 'contact'],
			'payload' => [
				'firstname' => '   ',
				'lastname' => '',
				'dateofbirth' => '   '
			]
		], [
			'keep_meta' => 'x'
		]);

		$out = $p->parse($item);

		$this->assertSame(['keep_meta' => 'x'], $out->metadata);
	}

	public function testParseAcceptsPayloadAsObjectAndContentAsObject(): void {
		$p = new XrmContactMetaParserAgentResource('x13');

		$content = (object)[
			'type' => (object)['alias' => 'contact'],
			'payload' => (object)[
				'firstname' => 123,
				'lastname' => true,
				'dateofbirth' => '2000-01-02'
			]
		];

		$item = $this->makeItem($content);

		$out = $p->parse($item);

		$contact = $out->metadata['contact'];

		$this->assertSame('123', $contact['firstname']);
		$this->assertSame('1', $contact['lastname']);
		$this->assertSame('2000-01-02', $contact['dateofbirth']);

		$expected = (new \DateTimeImmutable('2000-01-02', new \DateTimeZone('UTC')))->setTime(0, 0)->getTimestamp();
		$this->assertSame($expected, $contact['birth_ts']);
	}
}
