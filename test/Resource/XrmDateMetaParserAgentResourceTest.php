<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Resource\XrmDateMetaParserAgentResource;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;

/**
 * @covers \MissionBayXrm\Resource\XrmDateMetaParserAgentResource
 */
class XrmDateMetaParserAgentResourceTest extends TestCase {

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
		$this->assertSame('xrmdatemetaparseragentresource', XrmDateMetaParserAgentResource::getName());
	}

	public function testGetDescription(): void {
		$p = new XrmDateMetaParserAgentResource('x1');

		$this->assertSame(
			'Adds type-specific metadata for XRM entries with type_alias=date (start_ts, end_ts, allday).',
			$p->getDescription()
		);
	}

	public function testGetPriority(): void {
		$p = new XrmDateMetaParserAgentResource('x2');
		$this->assertSame(10, $p->getPriority());
	}

	public function testSupportsReturnsFalseForNonAgentContentItem(): void {
		$p = new XrmDateMetaParserAgentResource('x3');
		$this->assertFalse($p->supports(['x' => 1]));
	}

	public function testSupportsReturnsFalseWhenContentIsNotArrayOrObject(): void {
		$p = new XrmDateMetaParserAgentResource('x4');

		$item = $this->makeItem('not-structured', ['type_alias' => 'date']);

		$this->assertFalse($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromStructuredContent(): void {
		$p = new XrmDateMetaParserAgentResource('x5');

		$item = $this->makeItem([
			'type' => ['alias' => 'date'],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromMetadataFallback(): void {
		$p = new XrmDateMetaParserAgentResource('x6');

		$item = $this->makeItem([
			'type' => [],
			'payload' => []
		], [
			'type_alias' => 'date'
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testParseThrowsForNonAgentContentItem(): void {
		$p = new XrmDateMetaParserAgentResource('x7');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('XrmDateMetaParser expects AgentContentItem.');

		$p->parse(['x' => 1]);
	}

	public function testParseAddsStartEndTimestampsFromMysqlDateTimeAndDate(): void {
		$p = new XrmDateMetaParserAgentResource('x8');

		$item = $this->makeItem([
			'type' => ['alias' => 'date'],
			'payload' => [
				'start' => '2020-01-02 03:04:05',
				'end' => '2020-01-03',
				'allday' => null
			]
		]);

		$out = $p->parse($item);

		$this->assertInstanceOf(AgentParsedContent::class, $out);
		$this->assertSame('', $out->text);
		$this->assertSame([], $out->attachments);

		$expectedStart = (new \DateTimeImmutable('2020-01-02 03:04:05', new \DateTimeZone('UTC')))->getTimestamp();
		$expectedEnd = (new \DateTimeImmutable('2020-01-03 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();

		$this->assertSame([
			'date' => [
				'start_ts' => $expectedStart,
				'end_ts' => $expectedEnd
			]
		], $out->metadata);
	}

	public function testParseIgnoresZeroDatesAndInvalidDates(): void {
		$p = new XrmDateMetaParserAgentResource('x9');

		$item = $this->makeItem([
			'type' => ['alias' => 'date'],
			'payload' => [
				'start' => '0000-00-00 00:00:00',
				'end' => 'not-a-date',
				'allday' => null
			]
		], [
			'keep' => 'x'
		]);

		$out = $p->parse($item);

		$this->assertSame(['keep' => 'x'], $out->metadata);
	}

	public function testParseAddsAllDayFlagFromVariousTruthyFalsyInputs(): void {
		$p = new XrmDateMetaParserAgentResource('x10');

		$item1 = $this->makeItem([
			'type' => ['alias' => 'date'],
			'payload' => [
				'allday' => true
			]
		]);

		$out1 = $p->parse($item1);
		$this->assertSame(['date' => ['allday' => 1]], $out1->metadata);

		$item2 = $this->makeItem([
			'type' => ['alias' => 'date'],
			'payload' => [
				'allday' => 'no'
			]
		]);

		$out2 = $p->parse($item2);
		$this->assertSame(['date' => ['allday' => 0]], $out2->metadata);

		$item3 = $this->makeItem([
			'type' => ['alias' => 'date'],
			'payload' => [
				'allday' => 1
			]
		]);

		$out3 = $p->parse($item3);
		$this->assertSame(['date' => ['allday' => 1]], $out3->metadata);

		$item4 = $this->makeItem([
			'type' => ['alias' => 'date'],
			'payload' => [
				'allday' => 'off'
			]
		]);

		$out4 = $p->parse($item4);
		$this->assertSame(['date' => ['allday' => 0]], $out4->metadata);
	}

	public function testParseDoesNotCreateDateMetadataWhenNoValidValuesExist(): void {
		$p = new XrmDateMetaParserAgentResource('x11');

		$item = $this->makeItem([
			'type' => ['alias' => 'date'],
			'payload' => [
				'start' => '',
				'end' => '0000-00-00',
				'allday' => 'maybe'
			]
		], [
			'keep_meta' => 'x'
		]);

		$out = $p->parse($item);

		$this->assertSame(['keep_meta' => 'x'], $out->metadata);
	}

	public function testParseMergesDateMetadataAndParserWinsOnConflicts(): void {
		$p = new XrmDateMetaParserAgentResource('x12');

		$item = $this->makeItem([
			'type' => ['alias' => 'date'],
			'payload' => [
				'start' => '2020-01-02',
				'allday' => 'true'
			]
		], [
			'date' => [
				'start_ts' => 123,
				'allday' => 0,
				'other' => 'x'
			]
		]);

		$out = $p->parse($item);

		$expectedStart = (new \DateTimeImmutable('2020-01-02 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();

		$this->assertSame([
			'date' => [
				'start_ts' => $expectedStart,
				'allday' => 1,
				'other' => 'x'
			]
		], $out->metadata);
	}

	public function testParseAcceptsPayloadAsObjectAndContentAsObject(): void {
		$p = new XrmDateMetaParserAgentResource('x13');

		$content = (object)[
			'type' => (object)['alias' => 'date'],
			'payload' => (object)[
				'start' => '2020-01-02 03:04:05',
				'end' => '2020-01-02 03:04:06',
				'allday' => 'yes'
			]
		];

		$item = $this->makeItem($content);

		$out = $p->parse($item);

		$expectedStart = (new \DateTimeImmutable('2020-01-02 03:04:05', new \DateTimeZone('UTC')))->getTimestamp();
		$expectedEnd = (new \DateTimeImmutable('2020-01-02 03:04:06', new \DateTimeZone('UTC')))->getTimestamp();

		$this->assertSame([
			'date' => [
				'start_ts' => $expectedStart,
				'end_ts' => $expectedEnd,
				'allday' => 1
			]
		], $out->metadata);
	}
}
