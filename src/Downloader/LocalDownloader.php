<?php
declare(strict_types=1);

namespace MacPaw\LocalMirroringPlugin\Downloader;

use Composer\Downloader\PathDownloader;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem as ComposerFilesystem;
use Composer\Util\Platform;
use MacPaw\LocalMirroringPlugin\Package\Archiver\ExcludeFinder;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Class LocalDownloader
 * Override \Composer\Downloader\PathDownloader\PathDownloader functionality:
 * - add exclude paths from config when using mirroring strategy
 * - use ExcludeFinder with more efficient path ignoring
 *
 * Lines with changes marked as "Changes by plugin"
 */
class LocalDownloader extends PathDownloader
{

    /**
     * {@inheritdoc}
     */
    public function download(PackageInterface $package, $path, PackageInterface $prevPackage = null, $output = true)
    {
        $url = $package->getDistUrl();
        $realUrl = realpath($url);
        if (false === $realUrl || !file_exists($realUrl) || !is_dir($realUrl)) {
            throw new \RuntimeException(
                sprintf(
                    'Source path "%s" is not found for package %s',
                    $url,
                    $package->getName()
                )
            );
        }

        if (strpos(realpath($path) . DIRECTORY_SEPARATOR, $realUrl . DIRECTORY_SEPARATOR) === 0) {
            // IMPORTANT NOTICE: If you wish to change this, don't. You are wasting your time and ours.
            //
            // Please see https://github.com/composer/composer/pull/5974 and https://github.com/composer/composer/pull/6174
            // for previous attempts that were shut down because they did not work well enough or introduced too many risks.
            throw new \RuntimeException(
                sprintf(
                    'Package %s cannot install to "%s" inside its source at "%s"',
                    $package->getName(),
                    realpath($path),
                    $realUrl
                )
            );
        }

        // Get the transport options with default values
        $transportOptions = $package->getTransportOptions() + ['symlink' => null, 'exclude' => []]; // Changes by plugin

        // When symlink transport option is null, both symlink and mirror are allowed
        $currentStrategy = self::STRATEGY_SYMLINK;
        $allowedStrategies = [self::STRATEGY_SYMLINK, self::STRATEGY_MIRROR];

        $mirrorPathRepos = getenv('COMPOSER_MIRROR_PATH_REPOS');
        if ($mirrorPathRepos) {
            $currentStrategy = self::STRATEGY_MIRROR;
        }

        if (true === $transportOptions['symlink']) {
            $currentStrategy = self::STRATEGY_SYMLINK;
            $allowedStrategies = [self::STRATEGY_SYMLINK];
        } elseif (false === $transportOptions['symlink']) {
            $currentStrategy = self::STRATEGY_MIRROR;
            $allowedStrategies = [self::STRATEGY_MIRROR];
        }

        $fileSystem = new Filesystem();
        $this->filesystem->removeDirectory($path);

        if ($output) {
            $this->io->writeError(
                sprintf(
                    '  - Installing <info>%s</info> (<comment>%s</comment>): ',
                    $package->getName(),
                    $package->getFullPrettyVersion()
                ),
                false
            );
        }

        $isFallback = false;
        if (self::STRATEGY_SYMLINK == $currentStrategy) {
            try {
                if (Platform::isWindows()) {
                    // Implement symlinks as NTFS junctions on Windows
                    $this->io->writeError(sprintf('Junctioning from %s', $url), false);
                    $this->filesystem->junction($realUrl, $path);
                } else {
                    $absolutePath = $path;
                    if (!$this->filesystem->isAbsolutePath($absolutePath)) {
                        $absolutePath = getcwd() . DIRECTORY_SEPARATOR . $path;
                    }
                    $shortestPath = $this->filesystem->findShortestPath($absolutePath, $realUrl);
                    $path = rtrim($path, "/");
                    $this->io->writeError(sprintf('Symlinking from %s', $url), false);
                    $fileSystem->symlink($shortestPath, $path);
                }
            } catch (IOException $e) {
                if (in_array(self::STRATEGY_MIRROR, $allowedStrategies)) {
                    $this->io->writeError('');
                    $this->io->writeError('    <error>Symlink failed, fallback to use mirroring!</error>');
                    $currentStrategy = self::STRATEGY_MIRROR;
                    $isFallback = true;
                } else {
                    throw new \RuntimeException(sprintf('Symlink from "%s" to "%s" failed!', $realUrl, $path));
                }
            }
        }

        // Fallback if symlink failed or if symlink is not allowed for the package
        if (self::STRATEGY_MIRROR == $currentStrategy) {
            $fs = new ComposerFilesystem();
            $realUrl = $fs->normalizePath($realUrl);

            $this->io->writeError(sprintf('%sMirroring from %s', $isFallback ? '    ' : '', $url), false);
            $iterator = new ExcludeFinder($realUrl, [], false, $transportOptions['exclude']); // Changes by plugin
            $fileSystem->mirror($realUrl, $path, $iterator);
        }

        $this->io->writeError('');
    }
}
