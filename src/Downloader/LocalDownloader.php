<?php
declare(strict_types=1);

namespace MacPaw\LocalMirroringPlugin\Downloader;

use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Downloader\PathDownloader;
use Composer\Package\PackageInterface;
use Composer\Util\Platform;
use MacPaw\LocalMirroringPlugin\Package\Archiver\ExcludeFinder;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

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
    public function install(PackageInterface $package, $path, $output = true)
    {
        $url = $package->getDistUrl();
        $realUrl = realpath($url);

        if (realpath($path) === $realUrl) {
            if ($output) {
                $this->io->writeError("  - " . InstallOperation::format($package).': Source already present');
            } else {
                $this->io->writeError('Source already present', false);
            }

            return;
        }

        // Get the transport options with default values
        $transportOptions = $package->getTransportOptions() + array('symlink' => null, 'relative' => true);

        // When symlink transport option is null, both symlink and mirror are allowed
        $currentStrategy = self::STRATEGY_SYMLINK;
        $allowedStrategies = array(self::STRATEGY_SYMLINK, self::STRATEGY_MIRROR);

        $mirrorPathRepos = getenv('COMPOSER_MIRROR_PATH_REPOS');
        if ($mirrorPathRepos) {
            $currentStrategy = self::STRATEGY_MIRROR;
        }

        if (true === $transportOptions['symlink']) {
            $currentStrategy = self::STRATEGY_SYMLINK;
            $allowedStrategies = array(self::STRATEGY_SYMLINK);
        } elseif (false === $transportOptions['symlink']) {
            $currentStrategy = self::STRATEGY_MIRROR;
            $allowedStrategies = array(self::STRATEGY_MIRROR);
        }

        if (Platform::isWindows()) { // Changes by plugin
            $currentStrategy = self::STRATEGY_MIRROR;
            $allowedStrategies = array(self::STRATEGY_MIRROR);
        }

        $symfonyFilesystem = new SymfonyFilesystem();
        $this->filesystem->removeDirectory($path);

        if ($output) {
            $this->io->writeError("  - " . InstallOperation::format($package).': ', false);
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
                    if ($transportOptions['relative']) {
                        $symfonyFilesystem->symlink($shortestPath, $path);
                    } else {
                        $symfonyFilesystem->symlink($realUrl, $path);
                    }
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
            $realUrl = $this->filesystem->normalizePath($realUrl);

            $this->io->writeError(sprintf('%sMirroring from %s', $isFallback ? '    ' : '', $url), false);
            $iterator = new ExcludeFinder($realUrl, [], false, $transportOptions['exclude'] ?? []); // Changes by plugin
            $symfonyFilesystem->mirror($realUrl, $path, $iterator);
        }

        if ($output) {
            $this->io->writeError('');
        }
    }
}
