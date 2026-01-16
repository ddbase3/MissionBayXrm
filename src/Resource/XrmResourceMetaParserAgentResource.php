<?php declare(strict_types=1);

namespace MissionBayXrm\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

final class XrmResourceMetaParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	public static function getName(): string {
		return 'xrmresourcemetaparseragentresource';
	}

	public function getDescription(): string {
		return 'Adds type-specific metadata for XRM entries with type_alias=resource (quota, used).';
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

		return $this->detectTypeAliasFromItem($item) === 'resource';
	}

	public function parse(mixed $item): AgentParsedContent {
		if (!$item instanceof AgentContentItem) {
			throw new \InvalidArgumentException('XrmResourceMetaParser expects AgentContentItem.');
		}

		$structured = is_array($item->content) ? $item->content : (array)$item->content;
		$meta = is_array($item->metadata) ? $item->metadata : [];

		$payload = $this->getPayloadArray($structured);

		$resourceMeta = [];

		$quota = $this->toIntOrNull($payload['quota'] ?? null);
		if ($quota !== null) {
			$resourceMeta['quota'] = $quota;
		}

		$used = $this->toIntOrNull($payload['used'] ?? null);
		if ($used !== null) {
			$resourceMeta['used'] = $used;
		}

		if (!empty($resourceMeta)) {
			$existing = $meta['resource'] ?? null;
			if (!is_array($existing)) {
				$existing = [];
			}

			// parser wins on conflicts
			$meta['resource'] = array_merge($existing, $resourceMeta);
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
}
