<?php

namespace MacPaw\LocalMirroringPlugin\Package\Archiver;

use Composer\Package\Archiver\ArchivableFilesFinder;

use Composer\Package\Archiver\ComposerExcludeFilter;
use Composer\Package\Archiver\GitExcludeFilter;
use Composer\Package\Archiver\HgExcludeFilter;
use Composer\Util\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Class ExcludeFinder
 * Override \Composer\Package\Archiver\ArchivableFilesFinder functionality:
 * passthru exclude parameter Finder
 *
 * Lines with changes marked as "Changes by plugin"
 */
class ExcludeFinder extends ArchivableFilesFinder
{
    /**
     * Initializes the internal Symfony Finder with appropriate filters
     *
     * @param string $sources Path to source files to be archived
     * @param array $excludes Composer's own exclude rules from composer.json
     * @param bool $ignoreFilters Ignore filters when looking for files
     * @param array $dirExcludes Directories to ignore // Changes by plugin
     */
    public function __construct($sources, array $excludes, $ignoreFilters = false, array $dirExcludes = [])
    {
        $fs = new Filesystem();

        $sources = $fs->normalizePath(realpath($sources));

        if ($ignoreFilters) {
            $filters = array();
        } else {
            $filters = array(
                new HgExcludeFilter($sources),
                new GitExcludeFilter($sources),
                new ComposerExcludeFilter($sources, $excludes),
            );
        }

        $this->finder = new Finder();

        $filter = function (\SplFileInfo $file) use ($sources, $filters, $fs) {
            if ($file->isLink() && strpos($file->getRealPath(), $sources) !== 0) {
                return false;
            }

            $relativePath = preg_replace(
                '#^'.preg_quote($sources, '#').'#',
                '',
                $fs->normalizePath($file->getRealPath())
            );

            $exclude = false;
            foreach ($filters as $filter) {
                $exclude = $filter->filter($relativePath, $exclude);
            }

            return !$exclude;
        };

        if (method_exists($filter, 'bindTo')) {
            $filter = $filter->bindTo(null);
        }

        $this->finder
            ->in($sources)
            ->filter($filter)
            ->exclude($dirExcludes) // Changes by plugin
            ->ignoreVCS(true)
            ->ignoreDotFiles(false);

        \FilterIterator::__construct($this->finder->getIterator());
    }
}
