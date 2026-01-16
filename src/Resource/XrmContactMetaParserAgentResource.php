<?php declare(strict_types=1);

namespace MissionBayXrm\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

final class XrmContactMetaParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	public static function getName(): string {
		return 'xrmcontactmetaparseragentresource';
	}

	public function getDescription(): string {
		return 'Adds type-specific metadata for XRM entries with type_alias=contact (firstname, lastname, dateofbirth, birth_ts).';
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

		return $this->detectTypeAliasFromItem($item) === 'contact';
	}

	public function parse(mixed $item): AgentParsedContent {
		if (!$item instanceof AgentContentItem) {
			throw new \InvalidArgumentException('XrmContactMetaParser expects AgentContentItem.');
		}

		$structured = is_array($item->content) ? $item->content : (array)$item->content;
		$meta = is_array($item->metadata) ? $item->metadata : [];

		$payload = $this->getPayloadArray($structured);

		$contactMeta = [];

		$first = $this->asTrimmedString($payload['firstname'] ?? null);
		if ($first !== '') {
			$contactMeta['firstname'] = $first;
		}

		$last = $this->asTrimmedString($payload['lastname'] ?? null);
		if ($last !== '') {
			$contactMeta['lastname'] = $last;
		}

		// dateofbirth: ISO + birth_ts for range filters
		$dob = $this->asTrimmedString($payload['dateofbirth'] ?? null);
		$dob = $this->normalizeIsoDate($dob);
		if ($dob !== '') {
			$contactMeta['dateofbirth'] = $dob;

			$ts = $this->toUnixTimestampUtc($dob);
			if ($ts !== null) {
				$contactMeta['birth_ts'] = $ts;
			}
		}

		if (!empty($contactMeta)) {
			$existing = $meta['contact'] ?? null;
			if (!is_array($existing)) {
				$existing = [];
			}

			// parser wins on conflicts
			$meta['contact'] = array_merge($existing, $contactMeta);
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

	private function toUnixTimestampUtc(string $iso): ?int {
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
