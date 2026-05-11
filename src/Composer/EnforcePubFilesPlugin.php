<?php
/**
 * Copyright © OpenGento, All rights reserved.
 * See LICENSE bundled with this library for license details.
 */
declare(strict_types=1);

namespace Opengento\FrankenPhpBase\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;

use function file_exists;
use function getcwd;
use function is_dir;
use function md5_file;
use function realpath;
use function sprintf;

/**
 * Re-asserts the pub/*.php worker shims after any package install/update so
 * that magento/magento2-base re-installs (e.g. on quarterly patch day) can't
 * silently overwrite the worker entry points with stock Magento files.
 *
 * Why this plugin exists
 * ----------------------
 * The companion magento-composer-installer hardcodes magento/magento2-base
 * at the highest deploy priority (DeployManager::$highPriority = 10 +
 * maxPriority). On a fresh install our package deploys after magento2-base
 * and our worker shims win. But on a subsequent `composer update
 * magento/magento2-base` alone, our package isn't touched — its extra.map
 * never runs, and stock Magento's pub/index.php (etc.) come back, breaking
 * worker mode silently.
 *
 * This plugin listens to POST_PACKAGE_INSTALL and POST_PACKAGE_UPDATE for
 * every package, and after any of them, copies our pub/*.php from
 * vendor/opengento/magento2-frankenphp-base/pub/ over the project's pub/
 * directory whenever the on-disk file differs from ours. Idempotent and
 * cheap (md5_file gates the copy).
 *
 * Priority -1000 ensures we run after magento-composer-installer's own
 * deploy step on the same package event, so we're observing the post-
 * deploy state of the project pub/ directory.
 *
 * Closes the second half of opengento/magento2-frankenphp-base#1.
 */
final class EnforcePubFilesPlugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'opengento/magento2-frankenphp-base';

    /** Files this plugin owns at the project's pub/ directory. */
    private const PUB_FILES = ['worker.php', 'index.php', 'static.php', 'get.php'];

    private ?Composer $composer = null;
    private ?IOInterface $io = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Nothing to release.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Intentionally do not delete project pub/ files on uninstall —
        // doing so would break the application until `composer install`
        // restores them from magento/magento2-base.
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => ['onAfterPackageOperation', -1000],
            PackageEvents::POST_PACKAGE_UPDATE => ['onAfterPackageOperation', -1000],
        ];
    }

    public function onAfterPackageOperation(PackageEvent $event): void
    {
        if ($this->composer === null || $this->io === null) {
            return;
        }
        $this->enforcePubFiles();
    }

    private function enforcePubFiles(): void
    {
        $ownPackage = $this->findOwnPackage();
        if ($ownPackage === null) {
            // We're not installed yet (or have been removed mid-batch).
            return;
        }

        $installPath = $this->composer?->getInstallationManager()->getInstallPath($ownPackage);
        if ($installPath === null) {
            return;
        }
        $sourceDir = $installPath . '/pub';

        $cwd = getcwd();
        if ($cwd === false) {
            return;
        }
        $targetDir = realpath($cwd) . '/pub';
        if (!is_dir($targetDir)) {
            // No pub/ in the project root yet — e.g., very early in a
            // create-project run before magento2-base has materialized.
            return;
        }

        foreach (self::PUB_FILES as $filename) {
            $this->syncFile($sourceDir . '/' . $filename, $targetDir . '/' . $filename, $filename);
        }
    }

    private function findOwnPackage(): ?PackageInterface
    {
        $localRepo = $this->composer?->getRepositoryManager()->getLocalRepository();
        if ($localRepo === null) {
            return null;
        }
        foreach ($localRepo->getPackages() as $package) {
            if ($package->getName() === self::PACKAGE_NAME) {
                return $package;
            }
        }
        return null;
    }

    private function syncFile(string $source, string $target, string $shortName): void
    {
        if (!file_exists($source)) {
            return;
        }
        $sourceHash = md5_file($source);
        $targetHash = file_exists($target) ? md5_file($target) : null;
        if ($sourceHash !== false && $sourceHash === $targetHash) {
            return;
        }

        if (@copy($source, $target)) {
            $this->io?->write(sprintf(
                '<info>[%s]</info> Restored pub/%s (overwritten by another package)',
                self::PACKAGE_NAME,
                $shortName,
            ));
        } else {
            $this->io?->writeError(sprintf(
                '<error>[%s]</error> Failed to restore pub/%s — check filesystem permissions',
                self::PACKAGE_NAME,
                $shortName,
            ));
        }
    }
}
