<?php
declare(strict_types=1);

namespace MacPaw\LocalMirroringPlugin\Downloader;

use Composer\Downloader\PathDownloader;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use MacPaw\LocalMirroringPlugin\Package\Archiver\ExcludeFinder;
use React\Promise\PromiseInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Composer\DependencyResolver\Operation\InstallOperation;

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
    private const STRATEGY_SYMLINK = 10;
    private const STRATEGY_MIRROR = 20;

    /**
     * {@inheritdoc}
     */
    public function install(PackageInterface $package, string $path, bool $output = true): PromiseInterface
    {
        $path = Filesystem::trimTrailingSlash($path);
        $url = $package->getDistUrl();
        $realUrl = realpath($url);

        if (realpath($path) === $realUrl) {
            if ($output) {
                $this->io->writeError("  - " . InstallOperation::format($package) . $this->getInstallOperationAppendix($package, $path));
            }

            return \React\Promise\resolve(null);
        }

        // Get the transport options with default values
        $transportOptions = $package->getTransportOptions() + ['relative' => true];

        [$currentStrategy, $allowedStrategies] = $this->computeAllowedStrategies($transportOptions);

        $symfonyFilesystem = new SymfonyFilesystem();
        $this->filesystem->removeDirectory($path);

        if ($output) {
            $this->io->writeError("  - " . InstallOperation::format($package).': ', false);
        }

        $isFallback = false;
        if (self::STRATEGY_SYMLINK === $currentStrategy) {
            try {
                if (Platform::isWindows()) {
                    // Implement symlinks as NTFS junctions on Windows
                    if ($output) {
                        $this->io->writeError(sprintf('Junctioning from %s', $url), false);
                    }
                    $this->filesystem->junction($realUrl, $path);
                } else {
                    $absolutePath = $path;
                    if (!$this->filesystem->isAbsolutePath($absolutePath)) {
                        $absolutePath = Platform::getCwd() . DIRECTORY_SEPARATOR . $path;
                    }
                    $shortestPath = $this->filesystem->findShortestPath($absolutePath, $realUrl);
                    $path = rtrim($path, "/");
                    if ($output) {
                        $this->io->writeError(sprintf('Symlinking from %s', $url), false);
                    }
                    if ($transportOptions['relative']) {
                        $symfonyFilesystem->symlink($shortestPath.'/', $path);
                    } else {
                        $symfonyFilesystem->symlink($realUrl.'/', $path);
                    }
                }
            } catch (IOException $e) {
                if (in_array(self::STRATEGY_MIRROR, $allowedStrategies, true)) {
                    if ($output) {
                        $this->io->writeError('');
                        $this->io->writeError('    <error>Symlink failed, fallback to use mirroring!</error>');
                    }
                    $currentStrategy = self::STRATEGY_MIRROR;
                    $isFallback = true;
                } else {
                    throw new \RuntimeException(sprintf('Symlink from "%s" to "%s" failed!', $realUrl, $path));
                }
            }
        }

        // Fallback if symlink failed or if symlink is not allowed for the package
        if (self::STRATEGY_MIRROR === $currentStrategy) {
            $realUrl = $this->filesystem->normalizePath($realUrl);

            if ($output) {
                $this->io->writeError(sprintf('%sMirroring from %s', $isFallback ? '    ' : '', $url), false);
            }
            $iterator = new ExcludeFinder($realUrl, [], false, $transportOptions['exclude']); // Changes by plugin
            $symfonyFilesystem->mirror($realUrl, $path, $iterator);
        }

        if ($output) {
            $this->io->writeError('');
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @param mixed[] $transportOptions
     *
     * @phpstan-return array{self::STRATEGY_*, non-empty-list<self::STRATEGY_*>}
     */
    private function computeAllowedStrategies(array $transportOptions): array
    {
        // When symlink transport option is null, both symlink and mirror are allowed
        $currentStrategy = self::STRATEGY_SYMLINK;
        $allowedStrategies = [self::STRATEGY_SYMLINK, self::STRATEGY_MIRROR];

        $mirrorPathRepos = Platform::getEnv('COMPOSER_MIRROR_PATH_REPOS');
        if ($mirrorPathRepos) {
            $currentStrategy = self::STRATEGY_MIRROR;
        }

        $symlinkOption = $transportOptions['symlink'] ?? null;

        if (true === $symlinkOption) {
            $currentStrategy = self::STRATEGY_SYMLINK;
            $allowedStrategies = [self::STRATEGY_SYMLINK];
        } elseif (false === $symlinkOption) {
            $currentStrategy = self::STRATEGY_MIRROR;
            $allowedStrategies = [self::STRATEGY_MIRROR];
        }

        // Check we can use junctions safely if we are on Windows
        if (Platform::isWindows() && self::STRATEGY_SYMLINK === $currentStrategy && !$this->safeJunctions()) {
            if (!in_array(self::STRATEGY_MIRROR, $allowedStrategies, true)) {
                throw new \RuntimeException('You are on an old Windows / old PHP combo which does not allow Composer to use junctions/symlinks and this path repository has symlink:true in its options so copying is not allowed');
            }
            $currentStrategy = self::STRATEGY_MIRROR;
            $allowedStrategies = [self::STRATEGY_MIRROR];
        }

        // Check we can use symlink() otherwise
        if (!Platform::isWindows() && self::STRATEGY_SYMLINK === $currentStrategy && !function_exists('symlink')) {
            if (!in_array(self::STRATEGY_MIRROR, $allowedStrategies, true)) {
                throw new \RuntimeException('Your PHP has the symlink() function disabled which does not allow Composer to use symlinks and this path repository has symlink:true in its options so copying is not allowed');
            }
            $currentStrategy = self::STRATEGY_MIRROR;
            $allowedStrategies = [self::STRATEGY_MIRROR];
        }

        return [$currentStrategy, $allowedStrategies];
    }

    /**
     * Returns true if junctions can be created and safely used on Windows
     *
     * A PHP bug makes junction detection fragile, leading to possible data loss
     * when removing a package. See https://bugs.php.net/bug.php?id=77552
     *
     * For safety we require a minimum version of Windows 7, so we can call the
     * system rmdir which will preserve target content if given a junction.
     *
     * The PHP bug was fixed in 7.2.16 and 7.3.3 (requires at least Windows 7).
     */
    private function safeJunctions(): bool
    {
        // We need to call mklink, and rmdir on Windows 7 (version 6.1)
        return function_exists('proc_open') &&
            (PHP_WINDOWS_VERSION_MAJOR > 6 ||
                (PHP_WINDOWS_VERSION_MAJOR === 6 && PHP_WINDOWS_VERSION_MINOR >= 1));
    }
}
