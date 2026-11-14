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

namespace Base3\MissionBayXrm;

use Base3\CoreFoundation\Api\ICheck;
use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Api\IPlugin;

class MissionBayXrmPlugin implements IPlugin, ICheck {

	public function __construct(private readonly IContainer $container) {}

	// Implementation of IBase

	public static function getName(): string {
		return "missionbayxrmplugin";
	}

	// Implementation of IPlugin

	public function init() {
		$this->container->set(self::getName(), $this, IContainer::SHARED);
	}

	// Implementation of ICheck

	public function checkDependencies() {
		return array(
			'missionbayplugin_installed' => $this->container->get('missionbayplugin') ? 'Ok' : 'missionbayplugin not installed'
		);
	}
}
