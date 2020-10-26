<?php

namespace MacPaw\LocalMirroringPlugin;

use Composer\Cache;
use Composer\Composer;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreCommandRunEvent;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /** @var Composer */
    protected $composer;
    /** @var IOInterface */
    protected $io;

    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::INIT => [
                ['initDownloader', 0],
            ],
            PluginEvents::PRE_COMMAND_RUN => [
                ['removePackages', PHP_INT_MAX],
            ],
        ];
    }

    /** @inheritDoc */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /** @inheritDoc */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /** @inheritDoc */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @param PreCommandRunEvent $event
     */
    public function removePackages(PreCommandRunEvent $event)
    {
        if (!in_array($event->getCommand(), ['install', 'update'])) {
            return;
        }

        $package = $this->composer->getPackage();
        $packagesList = (array)($package->getExtra()['remove-packages'] ?? []);
        foreach ($packagesList as $packageName) {
            $packages = $this->composer->getRepositoryManager()->getLocalRepository()->search($packageName);
            foreach ($packages as $package) {
                $package = $this->composer->getRepositoryManager()->getLocalRepository()->findPackage(
                    $package['name'],
                    '*'
                );
                $this->io->write('Removing <fg=yellow>' . $package->getName() . '</>');
                $this->composer->getRepositoryManager()->getLocalRepository()->removePackage($package);
            }
        }
    }

    /**
     * @param Event $event
     */
    public function initDownloader(Event $event)
    {
        $config = $this->composer->getConfig();
        $cache = null;
        if ($config->get('cache-files-ttl') > 0) {
            $cache = new Cache($this->io, $config->get('cache-files-dir'), 'a-z0-9_./');
        }
        $this->composer->getDownloadManager()->setDownloader(
            'path',
            new Downloader\LocalDownloader($this->io, $config, $this->composer->getEventDispatcher(), $cache)
        );
    }
}
