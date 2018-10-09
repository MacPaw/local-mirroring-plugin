<?php

namespace MacPaw\LocalMirroringPlugin;

use Composer\Cache;
use Composer\Composer;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;

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
                ['removePaths', 0],
                ['initDownloader', 0],
            ],
        ];
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * @param Event $event
     */
    public function removePaths(Event $event)
    {
        $package = $this->composer->getPackage();
        $rootPath = $this->composer->getConfig()->get('vendor-dir');

        $pathsList = (array)($package->getExtra()['remove-paths'] ?? []);
        foreach ($pathsList as $relativePath) {
            $path = $rootPath . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
            $this->io->write('Removing <fg=red>' . $path . '</>');
            exec('rm -rf ' . escapeshellarg($path));
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
