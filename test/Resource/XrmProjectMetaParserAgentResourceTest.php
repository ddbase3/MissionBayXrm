<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Resource\XrmProjectMetaParserAgentResource;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;

/**
 * @covers \MissionBayXrm\Resource\XrmProjectMetaParserAgentResource
 */
class XrmProjectMetaParserAgentResourceTest extends TestCase {

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
		$this->assertSame('xrmprojectmetaparseragentresource', XrmProjectMetaParserAgentResource::getName());
	}

	public function testGetDescription(): void {
		$p = new XrmProjectMetaParserAgentResource('x1');

		$this->assertSame(
			'Adds type-specific metadata for XRM entries with type_alias=project (projid, alias, start_ts, deadline_ts).',
			$p->getDescription()
		);
	}

	public function testGetPriority(): void {
		$p = new XrmProjectMetaParserAgentResource('x2');
		$this->assertSame(10, $p->getPriority());
	}

	public function testSupportsReturnsFalseForNonAgentContentItem(): void {
		$p = new XrmProjectMetaParserAgentResource('x3');
		$this->assertFalse($p->supports(['x' => 1]));
	}

	public function testSupportsReturnsFalseWhenContentIsNotArrayOrObject(): void {
		$p = new XrmProjectMetaParserAgentResource('x4');

		$item = $this->makeItem('not-structured', ['type_alias' => 'project']);

		$this->assertFalse($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromStructuredContent(): void {
		$p = new XrmProjectMetaParserAgentResource('x5');

		$item = $this->makeItem([
			'type' => ['alias' => 'project'],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromMetadataFallback(): void {
		$p = new XrmProjectMetaParserAgentResource('x6');

		$item = $this->makeItem([
			'type' => [],
			'payload' => []
		], [
			'type_alias' => 'project'
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testParseThrowsForNonAgentContentItem(): void {
		$p = new XrmProjectMetaParserAgentResource('x7');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('XrmProjectMetaParser expects AgentContentItem.');

		$p->parse(['x' => 1]);
	}

	public function testParseAddsProjIdAliasAndTimestampsAndMergesProjectMetadataParserWins(): void {
		$p = new XrmProjectMetaParserAgentResource('x8');

		$item = $this->makeItem([
			'type' => ['alias' => 'project'],
			'payload' => [
				'projid' => '  P-42 ',
				'alias' => '  Alpha  ',
				'start' => '2020-01-02',
				'deadline' => '2020-02-03',
				'keep' => 'ok'
			]
		], [
			'project' => ['alias' => 'OLD', 'other' => 'x']
		]);

		$out = $p->parse($item);

		$this->assertInstanceOf(AgentParsedContent::class, $out);
		$this->assertSame('', $out->text);
		$this->assertSame([], $out->attachments);

		$expectedStart = (new \DateTimeImmutable('2020-01-02 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
		$expectedDeadline = (new \DateTimeImmutable('2020-02-03 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();

		$this->assertSame([
			'project' => [
				'alias' => 'Alpha',
				'other' => 'x',
				'projid' => 'P-42',
				'start_ts' => $expectedStart,
				'deadline_ts' => $expectedDeadline
			]
		], $out->metadata);

		$this->assertIsArray($out->structured);
		$this->assertSame('ok', $out->structured['payload']['keep']);
	}

	public function testParseOmitsInvalidDatesAndDoesNotCreateProjectMetadataWhenAllValuesEmpty(): void {
		$p = new XrmProjectMetaParserAgentResource('x9');

		$item = $this->makeItem([
			'type' => ['alias' => 'project'],
			'payload' => [
				'projid' => '   ',
				'alias' => '',
				'start' => '2020-02-31',
				'deadline' => 'not-a-date'
			]
		], [
			'keep_meta' => 'x'
		]);

		$out = $p->parse($item);

		$this->assertSame(['keep_meta' => 'x'], $out->metadata);
	}

	public function testParseAcceptsPayloadAsObjectAndContentAsObject(): void {
		$p = new XrmProjectMetaParserAgentResource('x10');

		$content = (object)[
			'type' => (object)['alias' => 'project'],
			'payload' => (object)[
				'projid' => 123,
				'alias' => true,
				'start' => '2000-01-02',
				'deadline' => '2000-01-03'
			]
		];

		$item = $this->makeItem($content);

		$out = $p->parse($item);

		$expectedStart = (new \DateTimeImmutable('2000-01-02 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
		$expectedDeadline = (new \DateTimeImmutable('2000-01-03 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();

		$this->assertSame([
			'project' => [
				'projid' => '123',
				'alias' => '1',
				'start_ts' => $expectedStart,
				'deadline_ts' => $expectedDeadline
			]
		], $out->metadata);
	}
}
