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

namespace Base3\MissionBayXrm\Resource;

use Base3\MissionBay\Api\IAgentVectorFilter;
use Base3\MissionBay\Resource\AbstractAgentResource;

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
