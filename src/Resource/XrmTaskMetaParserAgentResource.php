<?php declare(strict_types=1);

namespace MissionBayXrm\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

final class XrmTaskMetaParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	public static function getName(): string {
		return 'xrmtaskmetaparseragentresource';
	}

	public function getDescription(): string {
		return 'Adds type-specific metadata for XRM entries with type_alias=task (start_ts, deadline_ts, expense, done).';
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

		return $this->detectTypeAliasFromItem($item) === 'task';
	}

	public function parse(mixed $item): AgentParsedContent {
		if (!$item instanceof AgentContentItem) {
			throw new \InvalidArgumentException('XrmTaskMetaParser expects AgentContentItem.');
		}

		$structured = is_array($item->content) ? $item->content : (array)$item->content;
		$meta = is_array($item->metadata) ? $item->metadata : [];

		$payload = $this->getPayloadArray($structured);

		$taskMeta = [];

		$startIso = $this->asTrimmedString($payload['start'] ?? null);
		$startIso = $this->normalizeIsoDate($startIso);
		$startTs = $this->toUnixTimestampUtcFromIsoDate($startIso);
		if ($startTs !== null) {
			$taskMeta['start_ts'] = $startTs;
		}

		$deadlineIso = $this->asTrimmedString($payload['deadline'] ?? null);
		$deadlineIso = $this->normalizeIsoDate($deadlineIso);
		$deadlineTs = $this->toUnixTimestampUtcFromIsoDate($deadlineIso);
		if ($deadlineTs !== null) {
			$taskMeta['deadline_ts'] = $deadlineTs;
		}

		$expense = $this->toIntOrNull($payload['expense'] ?? null);
		if ($expense !== null) {
			$taskMeta['expense'] = $expense;
		}

		$done = $this->toBoolFromMixed($payload['done'] ?? null);
		if ($done !== null) {
			$taskMeta['done'] = $done ? 1 : 0;
		}

		if (!empty($taskMeta)) {
			$existing = $meta['task'] ?? null;
			if (!is_array($existing)) {
				$existing = [];
			}

			// parser wins on conflicts
			$meta['task'] = array_merge($existing, $taskMeta);
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

	private function toIntOrNull(mixed $v): ?int {
		if (is_int($v)) {
			return $v;
		}

		if (is_bool($v)) {
			return $v ? 1 : 0;
		}

		if (is_float($v)) {
			return (int)round($v);
		}

		if (is_string($v)) {
			$s = trim($v);
			if ($s === '') {
				return null;
			}
			if (!preg_match('/^-?\d+$/', $s)) {
				return null;
			}
			return (int)$s;
		}

		return null;
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

	private function normalizeIsoDate(string $s): string {
		$s = trim($s);
		if ($s === '') {
			return '';
		}

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
			return '';
		}

		$y = (int)substr($s, 0, 4);
		$m = (int)substr($s, 5, 2);
		$d = (int)substr($s, 8, 2);

		if ($y < 1000 || $y > 9999) {
			return '';
		}

		if (!checkdate($m, $d, $y)) {
			return '';
		}

		return $s;
	}

	private function toUnixTimestampUtcFromIsoDate(string $iso): ?int {
		if ($iso === '') {
			return null;
		}

		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new \DateTimeZone('UTC'));
		if (!$dt) {
			return null;
		}

		return $dt->getTimestamp();
	}
}
