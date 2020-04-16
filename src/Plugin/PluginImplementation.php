<?php
declare(strict_types=1);
namespace TYPO3\CMS\Composer\Plugin;

/*
 * This file was taken from the typo3 console plugin package.
 * (c) Helmut Hummel <info@helhum.io>
 *
 * This file is part of the TYPO3 project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Composer\Semver\Constraint\EmptyConstraint;
use TYPO3\CMS\Composer\Plugin\Config as PluginConfig;
use TYPO3\CMS\Composer\Plugin\Core\IncludeFile;
use TYPO3\CMS\Composer\Plugin\Core\IncludeFile\AppDirToken;
use TYPO3\CMS\Composer\Plugin\Core\IncludeFile\BaseDirToken;
use TYPO3\CMS\Composer\Plugin\Core\IncludeFile\ComposerModeToken;
use TYPO3\CMS\Composer\Plugin\Core\IncludeFile\RootDirToken;
use TYPO3\CMS\Composer\Plugin\Core\IncludeFile\WebDirToken;
use TYPO3\CMS\Composer\Plugin\Core\ScriptDispatcher;
use TYPO3\CMS\Composer\Plugin\Util\Filesystem;

/**
 * Implementation of the Plugin to make further changes more robust on Composer updates
 */
class PluginImplementation
{
    /**
     * @var ScriptDispatcher
     */
    private $scriptDispatcher;

    /**
     * @var IncludeFile
     */
    private $includeFile;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @param Event $event
     * @param ScriptDispatcher $scriptDispatcher
     * @param IncludeFile $includeFile
     */
    public function __construct(
        Event $event,
        ScriptDispatcher $scriptDispatcher = null,
        IncludeFile $includeFile = null
    ) {
        $this->io = $event->getIO();
        $this->composer = $event->getComposer();
        $fileSystem = new Filesystem();
        $pluginConfig = PluginConfig::load($this->composer);

        $this->scriptDispatcher = $scriptDispatcher ?: new ScriptDispatcher($event);
        $this->includeFile = $includeFile
            ?: new IncludeFile(
                $this->io,
                $this->composer,
                [
                    new BaseDirToken($this->io, $pluginConfig),
                    new AppDirToken($this->io, $pluginConfig),
                    new WebDirToken($this->io, $pluginConfig),
                    new RootDirToken($this->io, $pluginConfig),
                    new ComposerModeToken($this->io, $pluginConfig),
                ],
                $fileSystem
            );
    }

    public function preAutoloadDump()
    {
        $this->ensureDisabledComposerInstallers();
        if ($this->composer->getPackage()->getName() === 'typo3/cms') {
            // Nothing to do typo3/cms is root package
            return;
        }
        $this->includeFile->register();
    }

    public function postAutoloadDump()
    {
        $this->scriptDispatcher->executeScripts();
    }

    private function ensureDisabledComposerInstallers()
    {
        $composerInstallersPackage = $this->composer->getRepositoryManager()->findPackage('composer/installers', new EmptyConstraint());
        if ($composerInstallersPackage === null) {
            return;
        }
        $rootPackage = $this->composer->getPackage();
        $rootExtra = $rootPackage->getExtra();
        $disabledInstallers = $rootExtra['installer-disable'] ?? [];
        if ($disabledInstallers === false) {
            $disabledInstallers = [];
        }
        if (!is_array($disabledInstallers)) {
            $disabledInstallers = [$disabledInstallers];
        }
        if (!in_array('typo3-cms', $disabledInstallers, true)) {
            $disabledInstallers[] = 'typo3-cms';
            $rootExtra['installer-disable'] = $disabledInstallers;
            // We need to remove the composer/installers and add it again to disable the installer
            $this->composer
                ->getInstallationManager()
                ->removeInstaller(
                    \Composer\Installers\Installer::class
                );
            $rootPackage->setExtra($rootExtra);
            $this->composer
                ->getInstallationManager()
                ->addInstaller(
                    //IOInterface $io, Composer $composer, $type = 'library', Filesystem $filesystem = null, BinaryInstaller $binaryInstaller = null
                    new \Composer\Installers\Installer($this->io, $this->composer)
                );
        }
    }
}
