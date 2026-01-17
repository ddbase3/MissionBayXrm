<?php declare(strict_types=1);

namespace Test\MissionBayXrm\Resource;

use PHPUnit\Framework\TestCase;
use MissionBayXrm\Resource\XrmAccountMetaParserAgentResource;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;

/**
 * @covers \MissionBayXrm\Resource\XrmAccountMetaParserAgentResource
 */
class XrmAccountMetaParserAgentResourceTest extends TestCase {

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
		$this->assertSame('xrmaccountmetaparseragentresource', XrmAccountMetaParserAgentResource::getName());
	}

	public function testGetDescription(): void {
		$p = new XrmAccountMetaParserAgentResource('x1');

		$this->assertSame(
			'Adds type-specific metadata for XRM entries with type_alias=account (accounttype) and removes sensitive payload fields (description, data0..data4).',
			$p->getDescription()
		);
	}

	public function testGetPriority(): void {
		$p = new XrmAccountMetaParserAgentResource('x2');
		$this->assertSame(10, $p->getPriority());
	}

	public function testSupportsReturnsFalseForNonAgentContentItem(): void {
		$p = new XrmAccountMetaParserAgentResource('x3');
		$this->assertFalse($p->supports(['x' => 1]));
	}

	public function testSupportsReturnsFalseWhenContentIsNotArrayOrObject(): void {
		$p = new XrmAccountMetaParserAgentResource('x4');

		$item = $this->makeItem('not-structured', ['type_alias' => 'account']);

		$this->assertFalse($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromStructuredContent(): void {
		$p = new XrmAccountMetaParserAgentResource('x5');

		$item = $this->makeItem([
			'type' => ['alias' => 'account'],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsDetectsTypeAliasFromMetadataFallback(): void {
		$p = new XrmAccountMetaParserAgentResource('x6');

		$item = $this->makeItem([
			'type' => [],
			'payload' => []
		], [
			'type_alias' => 'account'
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testSupportsIsCaseInsensitiveAndTrimsAlias(): void {
		$p = new XrmAccountMetaParserAgentResource('x7');

		$item = $this->makeItem([
			'type' => ['alias' => '  AcCoUnT  '],
			'payload' => []
		]);

		$this->assertTrue($p->supports($item));
	}

	public function testParseThrowsForNonAgentContentItem(): void {
		$p = new XrmAccountMetaParserAgentResource('x8');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('XrmAccountMetaParser expects AgentContentItem.');

		$p->parse(['x' => 1]);
	}

	public function testParseAddsAccountTypeToMetadataAndRemovesSensitivePayloadFields(): void {
		$p = new XrmAccountMetaParserAgentResource('x9');

		$item = $this->makeItem([
			'type' => ['alias' => 'account'],
			'payload' => [
				'accounttype' => '  Premium  ',
				'description' => 'secret',
				'data0' => 'x0',
				'data1' => 'x1',
				'data2' => 'x2',
				'data3' => 'x3',
				'data4' => 'x4',
				'keep' => 'ok'
			]
		], [
			'account' => ['accounttype' => 'OldValue', 'keep_meta' => 'm1'],
			'type_alias' => 'account'
		]);

		$out = $p->parse($item);

		$this->assertInstanceOf(AgentParsedContent::class, $out);
		$this->assertSame('', $out->text);
		$this->assertSame([], $out->attachments);

		$this->assertIsArray($out->metadata);
		$this->assertArrayHasKey('account', $out->metadata);
		$this->assertSame([
			'accounttype' => 'Premium',
			'keep_meta' => 'm1'
		], $out->metadata['account']);

		$this->assertIsArray($out->structured);
		$this->assertArrayHasKey('payload', $out->structured);

		$payload = $out->structured['payload'];

		$this->assertIsArray($payload);
		$this->assertSame('ok', $payload['keep']);

		$this->assertArrayNotHasKey('description', $payload);
		for ($i = 0; $i <= 4; $i++) {
			$this->assertArrayNotHasKey('data' . $i, $payload);
		}

		// Non-sensitive field used for metadata can remain in payload.
		$this->assertSame('  Premium  ', $payload['accounttype']);
	}

	public function testParseDoesNotCreateAccountMetadataWhenAccountTypeIsMissingOrEmpty(): void {
		$p = new XrmAccountMetaParserAgentResource('x10');

		$item = $this->makeItem([
			'type' => ['alias' => 'account'],
			'payload' => [
				'accounttype' => '   ',
				'description' => 'secret',
				'data0' => 'x0',
				'keep' => 'ok'
			]
		], [
			'account' => ['keep_meta' => 'm1']
		]);

		$out = $p->parse($item);

		$this->assertSame(['account' => ['keep_meta' => 'm1']], $out->metadata);

		$payload = $out->structured['payload'];
		$this->assertSame('ok', $payload['keep']);
		$this->assertArrayNotHasKey('description', $payload);
		$this->assertArrayNotHasKey('data0', $payload);
	}

	public function testParseAcceptsPayloadAsObjectAndContentAsObject(): void {
		$p = new XrmAccountMetaParserAgentResource('x11');

		$content = (object)[
			'type' => (object)['alias' => 'account'],
			'payload' => (object)[
				'accounttype' => 123,
				'description' => 'secret',
				'data4' => 'x4',
				'keep' => 'ok'
			]
		];

		$item = $this->makeItem($content);

		$out = $p->parse($item);

		$this->assertSame(['account' => ['accounttype' => '123']], $out->metadata);

		$payload = $out->structured['payload'];
		$this->assertSame('ok', $payload['keep']);
		$this->assertArrayNotHasKey('description', $payload);
		$this->assertArrayNotHasKey('data4', $payload);
	}

	public function testParseMergesAccountMetadataAndParserWinsOnConflicts(): void {
		$p = new XrmAccountMetaParserAgentResource('x12');

		$item = $this->makeItem([
			'type' => ['alias' => 'account'],
			'payload' => [
				'accounttype' => 'New'
			]
		], [
			'account' => [
				'accounttype' => 'Old',
				'other' => 'x'
			]
		]);

		$out = $p->parse($item);

		$this->assertSame([
			'account' => [
				'accounttype' => 'New',
				'other' => 'x'
			]
		], $out->metadata);
	}
}
