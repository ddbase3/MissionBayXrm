<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Resource\XrmFileMetaParserAgentResource;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;

/**
 * @covers \MissionBayXrm\Resource\XrmFileMetaParserAgentResource
 */
class XrmFileMetaParserAgentResourceTest extends TestCase {

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
		$this->assertSame('xrmfilemetaparseragentresource', XrmFileMetaParserAgentResource::getName());
	}

	public function testGetDescription(): void {
		$p = new XrmFileMetaParserAgentResource('x1');

		$this->assertSame(
			'Adds type-specific metadata for XRM entries with type_alias=file (mime_type, filename, size, url).',
			$p->getDescription()
		);
	}

	public function testGetPriority(): void {
		$p = new XrmFileMetaParserAgentResource('x2');
		$this->assertSame(10, $p->getPriority());
	}

	public function testSupportsReturnsFalseForNonAgentContentItem(): void {
		$p = new XrmFileMetaParserAgentResource('x3');
		$this->assertFalse($p->supports(['x' => 1]));
	}

	public function testSupportsReturnsFalseWhenContentIsNotArrayOrObject(): void {
		$p = new XrmFileMetaParserAgentResource('x4');

		$item = $this->makeItem('not-structured', ['type_alias' => 'file']);

		$this->assertFalse($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromStructuredContent(): void {
		$p = new XrmFileMetaParserAgentResource('x5');

		$item = $this->makeItem([
			'type' => ['alias' => 'file'],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromMetadataFallback(): void {
		$p = new XrmFileMetaParserAgentResource('x6');

		$item = $this->makeItem([
			'type' => [],
			'payload' => []
		], [
			'type_alias' => 'file'
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testParseThrowsForNonAgentContentItem(): void {
		$p = new XrmFileMetaParserAgentResource('x7');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('XrmFileMetaParser expects AgentContentItem.');

		$p->parse(['x' => 1]);
	}

	public function testParseAddsMimeFilenameSizeUrlAndMergesFileMetadataParserWins(): void {
		$p = new XrmFileMetaParserAgentResource('x8');

		$item = $this->makeItem([
			'type' => ['alias' => 'file'],
			'payload' => [
				'mime' => ' application/pdf ',
				'filename' => '  doc.pdf  ',
				'size' => '123',
				'url' => ' https://example.test/doc.pdf ',
				'keep' => 'ok'
			]
		], [
			'file' => ['mime_type' => 'old', 'other' => 'x']
		]);

		$out = $p->parse($item);

		$this->assertInstanceOf(AgentParsedContent::class, $out);
		$this->assertSame('', $out->text);
		$this->assertSame([], $out->attachments);

		$this->assertSame([
			'file' => [
				'mime_type' => 'application/pdf',
				'other' => 'x',
				'filename' => 'doc.pdf',
				'size' => 123,
				'url' => 'https://example.test/doc.pdf'
			]
		], $out->metadata);

		$this->assertIsArray($out->structured);
		$this->assertSame('ok', $out->structured['payload']['keep']);
	}

	public function testParseIgnoresEmptyStringsAndNonPositiveSize(): void {
		$p = new XrmFileMetaParserAgentResource('x9');

		$item = $this->makeItem([
			'type' => ['alias' => 'file'],
			'payload' => [
				'mime' => '   ',
				'filename' => '',
				'size' => 0,
				'url' => '   '
			]
		], [
			'keep_meta' => 'x'
		]);

		$out = $p->parse($item);

		$this->assertSame(['keep_meta' => 'x'], $out->metadata);
	}

	public function testParseAcceptsSizeAsIntOrDigitStringOnly(): void {
		$p = new XrmFileMetaParserAgentResource('x10');

		$item1 = $this->makeItem([
			'type' => ['alias' => 'file'],
			'payload' => [
				'size' => 42
			]
		]);

		$out1 = $p->parse($item1);
		$this->assertSame(['file' => ['size' => 42]], $out1->metadata);

		$item2 = $this->makeItem([
			'type' => ['alias' => 'file'],
			'payload' => [
				'size' => '0042'
			]
		]);

		$out2 = $p->parse($item2);
		$this->assertSame(['file' => ['size' => 42]], $out2->metadata);

		$item3 = $this->makeItem([
			'type' => ['alias' => 'file'],
			'payload' => [
				'size' => '42.5'
			]
		]);

		$out3 = $p->parse($item3);
		$this->assertSame([], $out3->metadata);
	}

	public function testParseAcceptsPayloadAsObjectAndContentAsObject(): void {
		$p = new XrmFileMetaParserAgentResource('x11');

		$content = (object)[
			'type' => (object)['alias' => 'file'],
			'payload' => (object)[
				'mime' => 'text/plain',
				'filename' => 'a.txt',
				'size' => 5,
				'url' => 'http://x/a.txt'
			]
		];

		$item = $this->makeItem($content);

		$out = $p->parse($item);

		$this->assertSame([
			'file' => [
				'mime_type' => 'text/plain',
				'filename' => 'a.txt',
				'size' => 5,
				'url' => 'http://x/a.txt'
			]
		], $out->metadata);
	}
}
