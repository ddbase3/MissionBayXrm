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

use Base3\MissionBay\Dto\AgentContentItem;
use Base3\MissionBay\Dto\AgentParsedContent;
use Base3\MissionBayXrm\Resource\XrmAccountMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmAddressMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmContactMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmDateMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmFileMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmProductMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmProjectMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmResourceMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmTagMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmTaskMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmTextMetaParserAgentResource;
use PHPUnit\Framework\TestCase;

/**
 * Verifies selection isolation across XRM type-specific metadata parsers.
 */
final class XrmMetaParserIsolationAdditionalTest extends TestCase {

	public function testExactlyOneTypeSpecificParserSupportsEachStructuredType(): void {
		$parsers = $this->parsers();
		foreach ($parsers as $alias => $parser) {
			$item = $this->item(['type' => ['alias' => $alias], 'payload' => []]);
			$selected = [];
			foreach ($parsers as $candidateAlias => $candidate) {
				if ($candidate->supports($item)) {
					$selected[] = $candidateAlias;
				}
			}
			$this->assertSame([$alias], $selected);
		}
	}

	public function testStructuredAliasPrecedesConflictingMetadataFallback(): void {
		$parsers = $this->parsers();
		foreach ($parsers as $alias => $parser) {
			$item = $this->item(['type' => ['alias' => 'other'], 'payload' => []], ['type_alias' => $alias]);
			$this->assertFalse($parser->supports($item));
		}
	}

	public function testMetadataAliasIsUsedOnlyWhenStructuredAliasIsBlank(): void {
		$parsers = $this->parsers();
		foreach ($parsers as $alias => $parser) {
			$item = $this->item(['type' => ['alias' => '  '], 'payload' => []], ['type_alias' => strtoupper($alias)]);
			$this->assertTrue($parser->supports($item));
		}
	}

	public function testNonStructuredContentIsRejectedDespiteMatchingMetadata(): void {
		foreach ($this->parsers() as $alias => $parser) {
			$this->assertFalse($parser->supports($this->item('raw bytes', ['type_alias' => $alias])));
		}
	}

	public function testEveryParserReturnsDomainMetadataWithoutChangingRoutingFields(): void {
		foreach ($this->parsers() as $alias => $parser) {
			$item = $this->item(['type' => ['alias' => $alias], 'payload' => []], ['content_uuid' => 'stable-uuid']);
			$originalHash = $item->hash;
			$result = $parser->parse($item);
			$this->assertInstanceOf(AgentParsedContent::class, $result);
			$this->assertSame('stable-uuid', $result->metadata['content_uuid']);
			$this->assertSame('my-collection', $item->collectionKey);
			$this->assertSame('upsert', $item->action);
			$this->assertSame($originalHash, $item->hash);
			$this->assertSame([], $result->attachments);
		}
	}

	public function testAccountPayloadRedactionDoesNotModifyOriginalContent(): void {
		$content = (object)[
			'type' => ['alias' => 'account'],
			'payload' => (object)[
				'accounttype' => '  business  ',
				'description' => 'private note',
				'data0' => 'private value',
				'data4' => 'private value',
				'public_field' => 'keep'
			]
		];
		$item = $this->item($content, ['content_uuid' => 'uuid']);
		$parser = new XrmAccountMetaParserAgentResource();
		$this->assertTrue($parser->supports($item));
		$result = $parser->parse($item);
		$this->assertSame('business', $result->metadata['account']['accounttype']);
		$this->assertSame('uuid', $result->metadata['content_uuid']);
		$this->assertSame('keep', $result->structured['payload']['public_field']);
		$this->assertArrayNotHasKey('description', $result->structured['payload']);
		$this->assertArrayNotHasKey('data0', $result->structured['payload']);
		$this->assertArrayNotHasKey('data4', $result->structured['payload']);
		$this->assertSame('private note', $content->payload->description);
		$this->assertSame('private value', $content->payload->data0);
	}

	private function item(mixed $content, array $metadata = []): AgentContentItem {
		return new AgentContentItem(
			action: 'upsert',
			collectionKey: 'my-collection',
			id: 'queue-42',
			hash: 'content-hash',
			contentType: 'application/json',
			content: $content,
			isBinary: false,
			size: 12,
			metadata: $metadata
		);
	}

	private function parsers(): array {
		return [
			'account' => new XrmAccountMetaParserAgentResource(),
			'address' => new XrmAddressMetaParserAgentResource(),
			'contact' => new XrmContactMetaParserAgentResource(),
			'date' => new XrmDateMetaParserAgentResource(),
			'file' => new XrmFileMetaParserAgentResource(),
			'product' => new XrmProductMetaParserAgentResource(),
			'project' => new XrmProjectMetaParserAgentResource(),
			'resource' => new XrmResourceMetaParserAgentResource(),
			'tag' => new XrmTagMetaParserAgentResource(),
			'task' => new XrmTaskMetaParserAgentResource(),
			'text' => new XrmTextMetaParserAgentResource()
		];
	}
}
