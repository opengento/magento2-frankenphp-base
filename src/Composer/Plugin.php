<?php
/**
 * Copyright © OpenGento, All rights reserved.
 * See LICENSE bundled with this library for license details.
 */
declare(strict_types=1);

namespace Opengento\FrankenPhpBase\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use ErrorException;
use MagentoHackathon\Composer\Magento\Deploy\Manager\Entry;
use MagentoHackathon\Composer\Magento\DeployManager;
use MagentoHackathon\Composer\Magento\Installer;
use MagentoHackathon\Composer\Magento\ProjectConfig;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private Installer $installer;
    private DeployManager $deployManager;

    /**
     * @throws ErrorException
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->installer = new Installer($io, $composer);
        $this->deployManager = new DeployManager($io);
        $this->deployManager->setSortPriority(
            $composer->getPackage()->getExtra()['magento-deploy-sort-priority'] ?? []
        );
        $this->installer->setDeployManager($this->deployManager);
        $this->installer->setConfig(new ProjectConfig($composer->getPackage()->getExtra()));
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Silence is golden...
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Silence is golden...
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => ['deployFrankenPhpBase', 10],
            PackageEvents::POST_PACKAGE_UPDATE => ['deployFrankenPhpBase', 10],
        ];
    }

    /**
     * @throws ErrorException
     */
    public function deployFrankenPhpBase(): void
    {
        $package = $this->composer->getRepositoryManager()
            ->getLocalRepository()
            ->findPackage('opengento/magento2-frankenphp-base', '*');

        if ($package !== null && isset($package->getExtra()['map'])) {
            $strategy = $this->installer->getDeployStrategy($package);
            $strategy->setMappings($this->installer->getParser($package)->getMappings());
            $deployManagerEntry = new Entry();
            $deployManagerEntry->setPackageName($package->getName());
            $deployManagerEntry->setDeployStrategy($strategy);
            $this->deployManager->addPackage($deployManagerEntry);
        }

        $this->deployManager->doDeploy();
    }
}
