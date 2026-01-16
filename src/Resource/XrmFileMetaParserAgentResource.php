<?php declare(strict_types=1);

namespace MissionBayXrm\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

final class XrmFileMetaParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	public static function getName(): string {
		return 'xrmfilemetaparseragentresource';
	}

	public function getDescription(): string {
		return 'Adds type-specific metadata for XRM entries with type_alias=file (mime_type, filename, size, url).';
	}

	public function getPriority(): int {
		return 10; // must be BEFORE StructuredObjectParser (100)
	}

	public function supports(mixed $item): bool {
		if (!$item instanceof AgentContentItem) {
			return false;
		}

		if (!(is_array($item->content) || is_object($item->content))) {
			return false;
		}

		return $this->detectTypeAliasFromItem($item) === 'file';
	}

	public function parse(mixed $item): AgentParsedContent {
		if (!$item instanceof AgentContentItem) {
			throw new \InvalidArgumentException("XrmFileMetaParser expects AgentContentItem.");
		}

		$structured = is_array($item->content) ? $item->content : (array)$item->content;
		$meta = is_array($item->metadata) ? $item->metadata : [];

		$payload = $this->getPayloadArray($structured);

		$fileMeta = [];

		// mime
		$mime = $payload['mime'] ?? null;
		$mime = is_string($mime) ? trim($mime) : '';
		if ($mime !== '') {
			$fileMeta['mime_type'] = $mime;
		}

		// filename
		$filename = $payload['filename'] ?? null;
		$filename = is_string($filename) ? trim($filename) : '';
		if ($filename !== '') {
			$fileMeta['filename'] = $filename;
		}

		// size (optional)
		$size = $payload['size'] ?? null;
		if (is_int($size) && $size > 0) {
			$fileMeta['size'] = $size;
		} else if (is_string($size) && ctype_digit($size) && (int)$size > 0) {
			$fileMeta['size'] = (int)$size;
		}

		// url (optional)
		$url = $payload['url'] ?? null;
		$url = is_string($url) ? trim($url) : '';
		if ($url !== '') {
			$fileMeta['url'] = $url;
		}

		if (!empty($fileMeta)) {
			$existing = $meta['file'] ?? null;
			if (!is_array($existing)) {
				$existing = [];
			}

			// later parser wins on conflicts
			$meta['file'] = array_merge($existing, $fileMeta);
		}

		return new AgentParsedContent(
			text: '',
			metadata: $meta,
			structured: $structured,
			attachments: []
		);
	}

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
