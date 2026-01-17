<?php declare(strict_types=1);

namespace MissionBayXrm\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

final class XrmAccountMetaParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	public static function getName(): string {
		return 'xrmaccountmetaparseragentresource';
	}

	public function getDescription(): string {
		return 'Adds type-specific metadata for XRM entries with type_alias=account (accounttype) and removes sensitive payload fields (description, data0..data4).';
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

		return $this->detectTypeAliasFromItem($item) === 'account';
	}

	public function parse(mixed $item): AgentParsedContent {
		if (!$item instanceof AgentContentItem) {
			throw new \InvalidArgumentException('XrmAccountMetaParser expects AgentContentItem.');
		}

		$structured = is_array($item->content) ? $item->content : (array)$item->content;
		$meta = is_array($item->metadata) ? $item->metadata : [];

		$payload = $this->getPayloadArray($structured);

		$accountMeta = [];

		$accountType = $this->asTrimmedString($payload['accounttype'] ?? null);
		if ($accountType !== '') {
			$accountMeta['accounttype'] = $accountType;
		}

		$payload = $this->stripSensitivePayloadFields($payload);

		if (!empty($accountMeta)) {
			$existing = $meta['account'] ?? null;
			if (!is_array($existing)) {
				$existing = [];
			}

			// parser wins on conflicts
			$meta['account'] = array_merge($existing, $accountMeta);
		}

		$structured = $this->setPayloadArray($structured, $payload);

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

	/**
	 * @param array<string,mixed> $structured
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function setPayloadArray(array $structured, array $payload): array {
		$structured['payload'] = $payload;
		return $structured;
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

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function stripSensitivePayloadFields(array $payload): array {
		unset($payload['description']);

		for ($i = 0; $i <= 4; $i++) {
			unset($payload['data' . $i]);
		}

		return $payload;
	}
}
