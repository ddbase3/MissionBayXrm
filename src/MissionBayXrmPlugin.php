<?php declare(strict_types=1);

namespace MissionBayXrm;

use Base3\Api\ICheck;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;

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
