<?php
namespace TYPO3\Asset\Service;

/*                                                                        *
 * This script belongs to the FLOW3.Asser framework.                      *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License, either version 3   *
 * of the License, or (at your option) any later version.                 *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use Assetic\Asset\AssetCollection;
use Assetic\Asset\FileAsset;
use Ttree\Medialib\Core\Exception\DomainNotFoundException;
use TYPO3\Asset\Asset\MergedAsset;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Configuration\ConfigurationManager;
use TYPO3\Flow\Object\ObjectManager;
use TYPO3\Flow\Object\ObjectManagerInterface;
use TYPO3\Flow\Package\PackageManagerInterface;
use TYPO3\Flow\Persistence\PersistenceManagerInterface;
use TYPO3\Flow\Reflection\ObjectAccess;
use TYPO3\Flow\Resource\ResourceManager;

/**
 * A Service which provides further information about a given locale
 * and the current state of the i18n and L10n components.
 *
 * @Flow\Scope("singleton")
 * @api
 */
class AssetService
{
    const CONFIGURATION_TYPE_ASSETS = 'Assets';

    /**
     *
     * @var array
     **/
    protected $requiredJs = array();

    /**
     * @var ConfigurationManager
     * @Flow\Inject
     */
    protected $configurationManager;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @var PersistenceManagerInterface
     * @Flow\Inject
     */
    protected $persistenceManager;

    /**
     * @var ObjectManagerInterface
     * @Flow\Inject
     */
    protected $objectManager;

    /**
     * @var \TYPO3\Flow\Security\Context
     * @Flow\Inject
     */
    protected $securityContext;

    public function __construct(ObjectManager $objectManager)
    {
        $packageManager = $objectManager->get(PackageManagerInterface::class);
        $lessphpPackage = $packageManager->getPackage('leafo.lessphp');
        $lessphpPath = $lessphpPackage->getPackagePath();
        require_once($lessphpPath . 'lessc.inc.php');
    }

    /**
     * @param $name
     * @param $namespace
     * @param array $bundle
     * @return array
     */
    public function compileAssets($name, $namespace, $bundle = array())
    {
        $bundle = $this->getBundle($name, 'Bundles.' . $namespace, $bundle);

        $filters = array();
        if (isset($bundle['Filters']))
            $filters = $this->createFiltersIntances($bundle['Filters']);

        $preCompileMerge = isset($bundle['PreCompileMerge']) ? $bundle['PreCompileMerge'] : FALSE;

        if ($preCompileMerge) {

            $as = new AssetCollection(array(
                new MergedAsset($bundle['Files'], $filters),
            ));

            $name = str_replace(':', '.', $name);
            return array($this->publish($as->dump(), $name . '.' . strtolower($namespace)));

        } else {
            $assets = array();
            foreach ($bundle['Files'] as $file) {
                $assets[] = new FileAsset($file, $filters);
            }
            $as = new AssetCollection($assets);

            $uris = array();
            foreach ($as as $leaf) {
                $uris[] = $this->publish($leaf->dump(), $leaf->getSourcePath());
            }
            return $uris;

        }
    }


    /**
     * @param $path
     * @return mixed
     */
    public function getAssetConfiguration($path)
    {
        return $this->configurationManager->getConfiguration(self::CONFIGURATION_TYPE_ASSETS, $path);
    }

    /**
     * @param array $conf
     * @return array
     */
    protected function processResourcePath(array $conf)
    {

        $getFileResource = function (&$file, $key) use (&$conf) {
            if (substr($file, 0, 11) !== 'resource://') {
                /** @var $resource \TYPO3\Flow\Resource\Resource */
                try {
                    $resource = ObjectAccess::getPropertyPath($this->securityContext, str_replace('current.securityContext.', '', $file));
                    if ($resource !== NULL) {
                        $file = 'resource://' . (string)$resource;
                    } else {
                        unset($conf['Files'][$key]);
                    }
                } catch (DomainNotFoundException $e) {
                    unset($conf['Files'][$key]);
                }
            }
        };


        if (isset($conf['Files']) && is_array($conf['Files'])) {
            array_walk($conf['Files'], $getFileResource, $conf['Files']);
        }

        return $conf;
    }

    /**
     * @param $bundle
     * @param $basePath
     * @param array $overrideSettings
     * @return array
     */
    public function getBundle($bundle, $basePath, $overrideSettings = array())
    {
        $bundles = $this->configurationManager->getConfiguration(self::CONFIGURATION_TYPE_ASSETS, $basePath);

        $conf = $bundles[$bundle];
        $conf = array_merge($conf, $overrideSettings);

        $conf = $this->processResourcePath($conf);

        if (isset($conf['Dependencies'])) {
            foreach ($conf['Dependencies'] as $dependency) {
                $conf = array_merge_recursive($this->getBundle($dependency, $basePath), $conf);
            }
        }
        if (isset($conf['Alterations'])) {
            foreach ($conf['Alterations'] as $key => $alterations) {
                if (is_array($alterations)) {
                    foreach ($alterations as $type => $files) {
                        $position = array_search($key, $conf['Files']);
                        switch ($type) {
                            case 'After':
                                array_splice($conf['Files'], $position + 1, 0, $files);
                                break;

                            case 'Before':
                                array_splice($conf['Files'], $position, 0, $files);
                                break;

                            case 'Replace':
                            case 'Instead':
                                array_splice($conf['Files'], $position, 1, $files);

                            default:
                                # code...
                                break;
                        }
                    }
                }
            }
        }

        return $conf;
    }

    /**
     * @param $name
     * @return array
     */
    public function getCssBundleUris($name)
    {
        return $this->compileAssets($name, 'Css');
    }

    /**
     * @param $name
     * @return array
     */
    public function getJsBundleUris($name)
    {
        return $this->compileAssets($name, 'Js');
    }

    /**
     * @param $filters
     * @return array
     */
    public function createFiltersIntances($filters)
    {
        $filterInstances = array();
        foreach ($filters as $filter => $conf) {
            $filterInstances[] = $this->createFilterInstance($filter, $conf);
        }
        return $filterInstances;
    }

    /**
     * @param $filter
     * @param $arguments
     * @return mixed
     */
    public function createFilterInstance($filter, $arguments)
    {
        switch (count($arguments)) {
            case 0:
                return $this->objectManager->get($filter);
            case 1:
                return $this->objectManager->get($filter, $arguments[0]);
            case 2:
                return $this->objectManager->get($filter, $arguments[0], $arguments[1]);
            case 3:
                return $this->objectManager->get($filter, $arguments[0], $arguments[1], $arguments[2]);
            case 4:
                return $this->objectManager->get($filter, $arguments[0], $arguments[1], $arguments[2], $arguments[3]);
            case 5:
                return $this->objectManager->get($filter, $arguments[0], $arguments[1], $arguments[2], $arguments[3], $arguments[4]);
            case 6:
                return $this->objectManager->get($filter, $arguments[0], $arguments[1], $arguments[2], $arguments[3], $arguments[4], $arguments[5]);
        }
    }

    /**
     * shortcut to publish some content
     *
     * @param  string $content
     * @param  string $filename
     * @return string $uri
     */
    public function publish($content, $filename)
    {
        $resource = $this->resourceManager->importResourceFromContent($content, $filename);
        $this->persistenceManager->whitelistObject($resource);
        return $this->resourceManager->getPublicPersistentResourceUri($resource);
    }

    /**
     * Add an Bundle to the required bundles
     *
     * @param string $name name of the Bundle to add
     * @param string $bundle name of the Bundle to add this Bundle to
     */
    public function addRequiredJs($name, $bundle = 'TYPO3.Asset:Required')
    {
        if (!isset($this->requiredJs[$bundle]))
            $this->requiredJs[$bundle] = array();

        $this->requiredJs[$bundle][] = $name;
    }

    /**
     * Compile all the Required Scripts up to this point
     * @param  string $bundleName name of the Bundle to get the Configuration from
     * @return array  an array containing the uris
     */
    public function getRequiredJs($bundleName = 'TYPO3.Asset:Required')
    {
        $bundle = array();

        if (isset($this->requiredJs[$bundleName]))
            $bundle['Dependencies'] = $this->requiredJs[$bundleName];

        return $this->compileAssets($bundleName, 'Js', $bundle);
    }
}
