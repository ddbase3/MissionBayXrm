<?php declare(strict_types=1);

namespace MissionBayXrm\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

final class XrmDateMetaParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	public static function getName(): string {
		return 'xrmdatemetaparseragentresource';
	}

	public function getDescription(): string {
		return 'Adds type-specific metadata for XRM entries with type_alias=date (start_ts, end_ts, allday).';
	}

	public function getPriority(): int {
		return 10; // before StructuredObjectParser (100)
	}

	public function supports(mixed $item): bool {
		if (!$item instanceof AgentContentItem) {
			return false;
		}

		if (!(is_array($item->content) || is_object($item->content))) {
			return false;
		}

		return $this->detectTypeAliasFromItem($item) === 'date';
	}

	public function parse(mixed $item): AgentParsedContent {
		if (!$item instanceof AgentContentItem) {
			throw new \InvalidArgumentException('XrmDateMetaParser expects AgentContentItem.');
		}

		$structured = is_array($item->content) ? $item->content : (array)$item->content;
		$meta = is_array($item->metadata) ? $item->metadata : [];

		$payload = $this->getPayloadArray($structured);

		$dateMeta = [];

		$startRaw = $this->asTrimmedString($payload['start'] ?? null);
		$startTs = $this->toUnixTimestampUtcFromMysqlDateTime($startRaw);
		if ($startTs !== null) {
			$dateMeta['start_ts'] = $startTs;
		}

		$endRaw = $this->asTrimmedString($payload['end'] ?? null);
		$endTs = $this->toUnixTimestampUtcFromMysqlDateTime($endRaw);
		if ($endTs !== null) {
			$dateMeta['end_ts'] = $endTs;
		}

		$allDay = $this->toBoolFromMixed($payload['allday'] ?? null);
		if ($allDay !== null) {
			$dateMeta['allday'] = $allDay ? 1 : 0;
		}

		if (!empty($dateMeta)) {
			$existing = $meta['date'] ?? null;
			if (!is_array($existing)) {
				$existing = [];
			}

			// parser wins on conflicts
			$meta['date'] = array_merge($existing, $dateMeta);
		}

		return new AgentParsedContent(
			text: '',
			metadata: $meta,
			structured: $structured,
			attachments: []
		);
	}

	// ---------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------

	private function detectTypeAliasFromItem(AgentContentItem $item): string {
		$structured = is_array($item->content) ? $item->content : (array)$item->content;

		$alias = $structured['type']['alias'] ?? null;
		if (is_string($alias) && trim($alias) !== '') {
			return strtolower(trim($alias));
		}

		$alias = $item->metadata['type_alias'] ?? null;
		if (is_string($alias) && trim($alias) !== '') {
			return strtolower(trim($alias));
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $structured
	 * @return array<string,mixed>
	 */
	private function getPayloadArray(array $structured): array {
		$p = $structured['payload'] ?? null;
		if (is_array($p)) {
			return $p;
		}
		if (is_object($p)) {
			return (array)$p;
		}
		return [];
	}

	private function asTrimmedString(mixed $v): string {
		if (!is_string($v)) {
			if (is_numeric($v) || is_bool($v)) {
				$v = (string)$v;
			} else {
				return '';
			}
		}

		return trim($v);
	}

	private function toBoolFromMixed(mixed $v): ?bool {
		if (is_bool($v)) {
			return $v;
		}

		if (is_int($v)) {
			return $v !== 0;
		}

		if (is_string($v)) {
			$s = strtolower(trim($v));
			if ($s === '1' || $s === 'true' || $s === 'yes' || $s === 'y' || $s === 'on') {
				return true;
			}
			if ($s === '0' || $s === 'false' || $s === 'no' || $s === 'n' || $s === 'off') {
				return false;
			}
		}

		return null;
	}

	private function toUnixTimestampUtcFromMysqlDateTime(string $s): ?int {
		$s = trim($s);
		if ($s === '' || $s === '0000-00-00 00:00:00' || $s === '0000-00-00') {
			return null;
		}

		$tz = new \DateTimeZone('UTC');

		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $s, $tz);
		if ($dt instanceof \DateTimeImmutable) {
			return $dt->getTimestamp();
		}

		// fallback: allow plain date "YYYY-MM-DD"
		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $s, $tz);
		if ($dt instanceof \DateTimeImmutable) {
			return $dt->getTimestamp();
		}

		return null;
	}
}
