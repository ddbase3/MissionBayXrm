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
use Base3\MissionBayXrm\Resource\XrmTagMetaParserAgentResource;
use Base3\MissionBay\Dto\AgentContentItem;
use Base3\MissionBay\Dto\AgentParsedContent;

/**
 * @covers \Base3\MissionBayXrm\Resource\XrmTagMetaParserAgentResource
 */
class XrmTagMetaParserAgentResourceTest extends TestCase {

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
		$this->assertSame('xrmtagmetaparseragentresource', XrmTagMetaParserAgentResource::getName());
	}

	public function testGetDescription(): void {
		$p = new XrmTagMetaParserAgentResource('x1');

		$this->assertSame(
			'Adds type-specific metadata for XRM entries with type_alias=tag (tag).',
			$p->getDescription()
		);
	}

	public function testGetPriority(): void {
		$p = new XrmTagMetaParserAgentResource('x2');
		$this->assertSame(10, $p->getPriority());
	}

	public function testSupportsReturnsFalseForNonAgentContentItem(): void {
		$p = new XrmTagMetaParserAgentResource('x3');
		$this->assertFalse($p->supports(['x' => 1]));
	}

	public function testSupportsReturnsFalseWhenContentIsNotArrayOrObject(): void {
		$p = new XrmTagMetaParserAgentResource('x4');

		$item = $this->makeItem('not-structured', ['type_alias' => 'tag']);

		$this->assertFalse($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromStructuredContent(): void {
		$p = new XrmTagMetaParserAgentResource('x5');

		$item = $this->makeItem([
			'type' => ['alias' => 'tag'],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromMetadataFallback(): void {
		$p = new XrmTagMetaParserAgentResource('x6');

		$item = $this->makeItem([
			'type' => [],
			'payload' => []
		], [
			'type_alias' => 'tag'
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testParseThrowsForNonAgentContentItem(): void {
		$p = new XrmTagMetaParserAgentResource('x7');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('XrmTagMetaParser expects AgentContentItem.');

		$p->parse(['x' => 1]);
	}

	public function testParseAddsTagAndMergesTagMetadataParserWins(): void {
		$p = new XrmTagMetaParserAgentResource('x8');

		$item = $this->makeItem([
			'type' => ['alias' => 'tag'],
			'payload' => [
				'tag' => '  Hello  ',
				'keep' => 'ok'
			]
		], [
			'tag' => ['tag' => 'OLD', 'other' => 'x']
		]);

		$out = $p->parse($item);

		$this->assertInstanceOf(AgentParsedContent::class, $out);
		$this->assertSame('', $out->text);
		$this->assertSame([], $out->attachments);

		$this->assertSame([
			'tag' => [
				'tag' => 'Hello',
				'other' => 'x'
			]
		], $out->metadata);

		$this->assertIsArray($out->structured);
		$this->assertSame('ok', $out->structured['payload']['keep']);
	}

	public function testParseDoesNotCreateTagMetadataWhenTagIsEmpty(): void {
		$p = new XrmTagMetaParserAgentResource('x9');

		$item = $this->makeItem([
			'type' => ['alias' => 'tag'],
			'payload' => [
				'tag' => '   '
			]
		], [
			'keep_meta' => 'x'
		]);

		$out = $p->parse($item);

		$this->assertSame(['keep_meta' => 'x'], $out->metadata);
	}

	public function testParseAcceptsPayloadAsObjectAndContentAsObject(): void {
		$p = new XrmTagMetaParserAgentResource('x10');

		$content = (object)[
			'type' => (object)['alias' => 'tag'],
			'payload' => (object)[
				'tag' => 123
			]
		];

		$item = $this->makeItem($content);

		$out = $p->parse($item);

		$this->assertSame([
			'tag' => [
				'tag' => '123'
			]
		], $out->metadata);
	}
}
