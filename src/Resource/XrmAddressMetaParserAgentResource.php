<?php declare(strict_types=1);

namespace MissionBayXrm\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

final class XrmAddressMetaParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	public static function getName(): string {
		return 'xrmaddressmetaparseragentresource';
	}

	public function getDescription(): string {
		return 'Adds address-related metadata (lat, lng, city, country) for XRM entries with type_alias=address.';
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

		return $this->detectTypeAliasFromItem($item) === 'address';
	}

	public function parse(mixed $item): AgentParsedContent {
		if (!$item instanceof AgentContentItem) {
			throw new \InvalidArgumentException('XrmAddressMetaParser expects AgentContentItem.');
		}

		$structured = is_array($item->content) ? $item->content : (array)$item->content;
		$meta = is_array($item->metadata) ? $item->metadata : [];

		$payload = $this->getPayloadArray($structured);

		$addressMeta = [];

		// lat / lng (numeric + rough sanity check)
		$lat = $payload['lat'] ?? null;
		$lng = $payload['lng'] ?? null;

		if (is_numeric($lat) && is_numeric($lng)) {
			$lat = (float)$lat;
			$lng = (float)$lng;

			if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
				$addressMeta['lat'] = $lat;
				$addressMeta['lng'] = $lng;
			}
		}

		// city
		$city = $payload['city'] ?? null;
		if (is_string($city)) {
			$city = trim($city);
			if ($city !== '') {
				$addressMeta['city'] = $city;
			}
		}

		// country
		$country = $payload['country'] ?? null;
		if (is_string($country)) {
			$country = trim($country);
			if ($country !== '') {
				$addressMeta['country'] = $country;
			}
		}

		if (!empty($addressMeta)) {
			$existing = $meta['address'] ?? null;
			if (!is_array($existing)) {
				$existing = [];
			}

			// parser gewinnt bei Konflikten
			$meta['address'] = array_merge($existing, $addressMeta);
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
}
