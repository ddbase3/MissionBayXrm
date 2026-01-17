<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Resource\XrmTaskMetaParserAgentResource;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;

/**
 * @covers \MissionBayXrm\Resource\XrmTaskMetaParserAgentResource
 */
class XrmTaskMetaParserAgentResourceTest extends TestCase {

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
		$this->assertSame('xrmtaskmetaparseragentresource', XrmTaskMetaParserAgentResource::getName());
	}

	public function testGetDescription(): void {
		$p = new XrmTaskMetaParserAgentResource('x1');

		$this->assertSame(
			'Adds type-specific metadata for XRM entries with type_alias=task (start_ts, deadline_ts, expense, done).',
			$p->getDescription()
		);
	}

	public function testGetPriority(): void {
		$p = new XrmTaskMetaParserAgentResource('x2');
		$this->assertSame(10, $p->getPriority());
	}

	public function testSupportsReturnsFalseForNonAgentContentItem(): void {
		$p = new XrmTaskMetaParserAgentResource('x3');
		$this->assertFalse($p->supports(['x' => 1]));
	}

	public function testSupportsReturnsFalseWhenContentIsNotArrayOrObject(): void {
		$p = new XrmTaskMetaParserAgentResource('x4');

		$item = $this->makeItem('not-structured', ['type_alias' => 'task']);

		$this->assertFalse($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromStructuredContent(): void {
		$p = new XrmTaskMetaParserAgentResource('x5');

		$item = $this->makeItem([
			'type' => ['alias' => 'task'],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromMetadataFallback(): void {
		$p = new XrmTaskMetaParserAgentResource('x6');

		$item = $this->makeItem([
			'type' => [],
			'payload' => []
		], [
			'type_alias' => 'task'
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testParseThrowsForNonAgentContentItem(): void {
		$p = new XrmTaskMetaParserAgentResource('x7');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('XrmTaskMetaParser expects AgentContentItem.');

		$p->parse(['x' => 1]);
	}

	public function testParseAddsTimestampsExpenseDoneAndMergesTaskMetadataParserWins(): void {
		$p = new XrmTaskMetaParserAgentResource('x8');

		$item = $this->makeItem([
			'type' => ['alias' => 'task'],
			'payload' => [
				'start' => '2020-01-02',
				'deadline' => '2020-02-03',
				'expense' => '42',
				'done' => 'yes',
				'keep' => 'ok'
			]
		], [
			'task' => ['done' => 0, 'other' => 'x']
		]);

		$out = $p->parse($item);

		$this->assertInstanceOf(AgentParsedContent::class, $out);
		$this->assertSame('', $out->text);
		$this->assertSame([], $out->attachments);

		$expectedStart = (new \DateTimeImmutable('2020-01-02 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
		$expectedDeadline = (new \DateTimeImmutable('2020-02-03 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();

		$this->assertSame([
			'task' => [
				'done' => 1,
				'other' => 'x',
				'start_ts' => $expectedStart,
				'deadline_ts' => $expectedDeadline,
				'expense' => 42
			]
		], $out->metadata);

		$this->assertIsArray($out->structured);
		$this->assertSame('ok', $out->structured['payload']['keep']);
	}

	public function testParseOmitsInvalidDatesAndInvalidExpenseAndInvalidDone(): void {
		$p = new XrmTaskMetaParserAgentResource('x9');

		$item = $this->makeItem([
			'type' => ['alias' => 'task'],
			'payload' => [
				'start' => '2020-02-31',
				'deadline' => 'not-a-date',
				'expense' => '1.5',
				'done' => 'maybe'
			]
		], [
			'keep_meta' => 'x'
		]);

		$out = $p->parse($item);

		$this->assertSame(['keep_meta' => 'x'], $out->metadata);
	}

	public function testParseDoneBoolParsing(): void {
		$p = new XrmTaskMetaParserAgentResource('x10');

		$item1 = $this->makeItem([
			'type' => ['alias' => 'task'],
			'payload' => ['done' => true]
		]);

		$out1 = $p->parse($item1);
		$this->assertSame(['task' => ['done' => 1]], $out1->metadata);

		$item2 = $this->makeItem([
			'type' => ['alias' => 'task'],
			'payload' => ['done' => 0]
		]);

		$out2 = $p->parse($item2);
		$this->assertSame(['task' => ['done' => 0]], $out2->metadata);

		$item3 = $this->makeItem([
			'type' => ['alias' => 'task'],
			'payload' => ['done' => 'OFF']
		]);

		$out3 = $p->parse($item3);
		$this->assertSame(['task' => ['done' => 0]], $out3->metadata);
	}

	public function testParseAcceptsPayloadAsObjectAndContentAsObject(): void {
		$p = new XrmTaskMetaParserAgentResource('x11');

		$content = (object)[
			'type' => (object)['alias' => 'task'],
			'payload' => (object)[
				'start' => '2000-01-02',
				'deadline' => '2000-01-03',
				'expense' => 7.2,
				'done' => '1'
			]
		];

		$item = $this->makeItem($content);

		$out = $p->parse($item);

		$expectedStart = (new \DateTimeImmutable('2000-01-02 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
		$expectedDeadline = (new \DateTimeImmutable('2000-01-03 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();

		$this->assertSame([
			'task' => [
				'start_ts' => $expectedStart,
				'deadline_ts' => $expectedDeadline,
				'expense' => 7,
				'done' => 1
			]
		], $out->metadata);
	}
}
