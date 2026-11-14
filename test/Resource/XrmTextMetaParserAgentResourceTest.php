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
use Base3\MissionBayXrm\Resource\XrmTextMetaParserAgentResource;
use Base3\MissionBay\Dto\AgentContentItem;
use Base3\MissionBay\Dto\AgentParsedContent;

/**
 * @covers \Base3\MissionBayXrm\Resource\XrmTextMetaParserAgentResource
 */
class XrmTextMetaParserAgentResourceTest extends TestCase {

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
		$this->assertSame('xrmtextmetaparseragentresource', XrmTextMetaParserAgentResource::getName());
	}

	public function testGetDescription(): void {
		$p = new XrmTextMetaParserAgentResource('x1');

		$this->assertSame(
			'Adds type-specific metadata for XRM entries with type_alias=text (mime).',
			$p->getDescription()
		);
	}

	public function testGetPriority(): void {
		$p = new XrmTextMetaParserAgentResource('x2');
		$this->assertSame(10, $p->getPriority());
	}

	public function testSupportsReturnsFalseForNonAgentContentItem(): void {
		$p = new XrmTextMetaParserAgentResource('x3');
		$this->assertFalse($p->supports(['x' => 1]));
	}

	public function testSupportsReturnsFalseWhenContentIsNotArrayOrObject(): void {
		$p = new XrmTextMetaParserAgentResource('x4');

		$item = $this->makeItem('not-structured', ['type_alias' => 'text']);

		$this->assertFalse($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromStructuredContent(): void {
		$p = new XrmTextMetaParserAgentResource('x5');

		$item = $this->makeItem([
			'type' => ['alias' => 'text'],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromMetadataFallback(): void {
		$p = new XrmTextMetaParserAgentResource('x6');

		$item = $this->makeItem([
			'type' => [],
			'payload' => []
		], [
			'type_alias' => 'text'
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testParseThrowsForNonAgentContentItem(): void {
		$p = new XrmTextMetaParserAgentResource('x7');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('XrmTextMetaParser expects AgentContentItem.');

		$p->parse(['x' => 1]);
	}

	public function testParseAddsMimeAndMergesTextMetadataParserWins(): void {
		$p = new XrmTextMetaParserAgentResource('x8');

		$item = $this->makeItem([
			'type' => ['alias' => 'text'],
			'payload' => [
				'mime' => '  text/plain  ',
				'keep' => 'ok'
			]
		], [
			'text' => ['mime' => 'OLD', 'other' => 'x']
		]);

		$out = $p->parse($item);

		$this->assertInstanceOf(AgentParsedContent::class, $out);
		$this->assertSame('', $out->text);
		$this->assertSame([], $out->attachments);

		$this->assertSame([
			'text' => [
				'mime' => 'text/plain',
				'other' => 'x'
			]
		], $out->metadata);

		$this->assertIsArray($out->structured);
		$this->assertSame('ok', $out->structured['payload']['keep']);
	}

	public function testParseDoesNotCreateTextMetadataWhenMimeIsEmpty(): void {
		$p = new XrmTextMetaParserAgentResource('x9');

		$item = $this->makeItem([
			'type' => ['alias' => 'text'],
			'payload' => [
				'mime' => '   '
			]
		], [
			'keep_meta' => 'x'
		]);

		$out = $p->parse($item);

		$this->assertSame(['keep_meta' => 'x'], $out->metadata);
	}

	public function testParseAcceptsPayloadAsObjectAndContentAsObject(): void {
		$p = new XrmTextMetaParserAgentResource('x10');

		$content = (object)[
			'type' => (object)['alias' => 'text'],
			'payload' => (object)[
				'mime' => 123
			]
		];

		$item = $this->makeItem($content);

		$out = $p->parse($item);

		$this->assertSame([
			'text' => [
				'mime' => '123'
			]
		], $out->metadata);
	}
}
