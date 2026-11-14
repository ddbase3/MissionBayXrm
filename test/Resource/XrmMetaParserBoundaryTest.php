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
use Base3\MissionBayXrm\Resource\XrmAccountMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmAddressMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmContactMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmDateMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmFileMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmProjectMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmResourceMetaParserAgentResource;
use Base3\MissionBayXrm\Resource\XrmTaskMetaParserAgentResource;
use PHPUnit\Framework\TestCase;

/**
 * Verifies boundaries of the existing XRM metadata conversions.
 */
final class XrmMetaParserBoundaryTest extends TestCase {

	public function testFileParserRejectsNonIntegralAndWhitespacePaddedSizes(): void {
		$parser = new XrmFileMetaParserAgentResource();
		foreach ([3.4, ' 12 ', '-12', '0', 0, true, null] as $size) {
			$result = $parser->parse($this->item('file', ['size' => $size], ['unchanged' => true]));
			$this->assertSame(['unchanged' => true], $result->metadata);
		}
		$result = $parser->parse($this->item('file', ['size' => '00012']));
		$this->assertSame(['file' => ['size' => 12]], $result->metadata);
	}

	public function testFileParserRetainsExistingMetadataForNonArrayPayload(): void {
		$parser = new XrmFileMetaParserAgentResource();
		$item = $this->item('file', 'not a structured payload', ['file' => ['category' => 'image']]);
		$result = $parser->parse($item);
		$this->assertSame(['file' => ['category' => 'image']], $result->metadata);
		$this->assertSame('not a structured payload', $result->structured['payload']);
	}

	public function testResourceParserConvertsFalseAndRoundsNegativeNumbers(): void {
		$parser = new XrmResourceMetaParserAgentResource();
		$result = $parser->parse($this->item('resource', ['quota' => false, 'used' => -1.5]));
		$this->assertSame(['resource' => ['quota' => 0, 'used' => -2]], $result->metadata);
	}

	public function testResourceParserDoesNotCoerceScientificNotationOrObjectPayload(): void {
		$parser = new XrmResourceMetaParserAgentResource();
		$result = $parser->parse($this->item('resource', ['quota' => '1e2', 'used' => new \stdClass()], ['source' => 'xrm']));
		$this->assertSame(['source' => 'xrm'], $result->metadata);
	}

	public function testDateParserNormalizesAllDayAliasesAndRejectsUnknownValues(): void {
		$parser = new XrmDateMetaParserAgentResource();
		foreach (['YES', ' Y ', 'on', '1', 1, true, -1] as $value) {
			$this->assertSame(['date' => ['allday' => 1]], $parser->parse($this->item('date', ['allday' => $value]))->metadata);
		}
		foreach (['NO', ' N ', 'off', 'false', '0', 0, false] as $value) {
			$this->assertSame(['date' => ['allday' => 0]], $parser->parse($this->item('date', ['allday' => $value]))->metadata);
		}
		foreach (['sometimes', 0.5, new \stdClass(), null] as $value) {
			$this->assertSame([], $parser->parse($this->item('date', ['allday' => $value]))->metadata);
		}
	}

	public function testTaskParserKeepsZeroExpenseAndNonzeroIntegerDoneFlag(): void {
		$parser = new XrmTaskMetaParserAgentResource();
		$result = $parser->parse($this->item('task', [
			'expense' => false,
			'done' => -1,
			'start' => 'invalid',
			'deadline' => '2026-02-30'
		]));
		$this->assertSame(['task' => ['expense' => 0, 'done' => 1]], $result->metadata);
	}

	public function testAddressParserAcceptsInclusiveCoordinateBounds(): void {
		$parser = new XrmAddressMetaParserAgentResource();
		$result = $parser->parse($this->item('address', ['lat' => '-90', 'lng' => '180']));
		$this->assertSame(['address' => ['lat' => -90.0, 'lng' => 180.0]], $result->metadata);
		$this->assertSame([], $parser->parse($this->item('address', ['lat' => '-90.01', 'lng' => '180']))->metadata);
		$this->assertSame([], $parser->parse($this->item('address', ['lat' => '-90', 'lng' => '180.01']))->metadata);
	}

	public function testContactParserValidatesBirthDateYearRangeAndLeapYears(): void {
		$parser = new XrmContactMetaParserAgentResource();
		foreach (['0999-12-31', '2025-02-29', '10000-01-01', '2026-02-30'] as $invalid) {
			$this->assertSame([], $parser->parse($this->item('contact', ['dateofbirth' => $invalid]))->metadata);
		}
		$result = $parser->parse($this->item('contact', ['dateofbirth' => '2000-02-29']));
		$this->assertSame('2000-02-29', $result->metadata['contact']['dateofbirth']);
		$this->assertSame(strtotime('2000-02-29 00:00:00 UTC'), $result->metadata['contact']['birth_ts']);
	}

	public function testProjectParserAcceptsScalarIdentifiersAndDateBoundaries(): void {
		$parser = new XrmProjectMetaParserAgentResource();
		$result = $parser->parse($this->item('project', [
			'projid' => true,
			'alias' => 7,
			'start' => '1000-01-01',
			'deadline' => '9999-12-31'
		]));
		$this->assertSame('1', $result->metadata['project']['projid']);
		$this->assertSame('7', $result->metadata['project']['alias']);
		$this->assertSame(strtotime('1000-01-01 UTC'), $result->metadata['project']['start_ts']);
		$this->assertSame(strtotime('9999-12-31 UTC'), $result->metadata['project']['deadline_ts']);
	}

	public function testAccountParserEliminatesScalarPayloadAndKeepsOtherMetadata(): void {
		$parser = new XrmAccountMetaParserAgentResource();
		$item = $this->item('account', 'secret free-text payload', ['origin' => 'xrm']);
		$result = $parser->parse($item);
		$this->assertSame([], $result->structured['payload']);
		$this->assertSame(['origin' => 'xrm'], $result->metadata);
		$this->assertSame('secret free-text payload', $item->content['payload']);
	}

	private function item(string $alias, mixed $payload, array $metadata = []): AgentContentItem {
		return new AgentContentItem(
			action: 'upsert',
			collectionKey: 'xrm',
			id: 'reference-1',
			hash: 'checksum',
			contentType: 'application/json',
			content: ['type' => ['alias' => $alias], 'payload' => $payload],
			isBinary: false,
			size: 1,
			metadata: $metadata
		);
	}
}
