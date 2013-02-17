<?php
namespace TYPO3\Asset;

use TYPO3\Flow\Package\Package as BasePackage;
use TYPO3\Flow\Annotations as Flow;

/**
 * Package base class of the TYPO3.Asset package.
 *
 * @Flow\Scope("singleton")
 */
class Package extends BasePackage {

	public function boot(\TYPO3\Flow\Core\Bootstrap $bootstrap) {
		$dispatcher = $bootstrap->getSignalSlotDispatcher();
		$dispatcher->connect('TYPO3\Flow\Configuration\ConfigurationManager', 'configurationManagerReady',
			function ($configurationManager) {
				$configurationManager->registerConfigurationType('Assets');
			}
		);
	}
}
?>