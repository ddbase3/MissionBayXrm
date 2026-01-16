<?php declare(strict_types=1);

namespace MissionBayXrm\Resource;

use MissionBay\Api\IAgentVectorFilter;
use MissionBay\Resource\AbstractAgentResource;

/**
 * XrmPublicActiveVectorFilterAgentResource
 *
 * Enforces XRM default visibility rules for retrieval:
 * - public = 1
 * - archive = 0
 *
 * This filter is intended to be docked into RetrievalAgentTool
 * and merged with other filter sources upstream.
 */
final class XrmPublicActiveVectorFilterAgentResource extends AbstractAgentResource implements IAgentVectorFilter {

	public static function getName(): string {
		return 'xrmpublicactivevectorfilteragentresource';
	}

	public function getDescription(): string {
		return 'Restricts vector retrieval to public, non-archived XRM entries.';
	}

	public function getFilterSpec(): ?array {
		return [
			'must' => [
				'public' => 1,
				'archive' => 0
			]
		];
	}
}
