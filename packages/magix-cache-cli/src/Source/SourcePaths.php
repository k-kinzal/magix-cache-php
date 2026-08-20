<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Source;

use function file_get_contents;

use FilesystemIterator;

use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function rtrim;
use function sort;

use SplFileInfo;

use function str_ends_with;
use function str_starts_with;

/**
 * Decides which directories and files are scanned for cache boundaries.
 */
final readonly class SourcePaths
{
    /**
     * Directory names that never contain application boundaries.
     */
    private const array SKIPPED = ['vendor', 'node_modules', '.git'];

    /**
     * Creates a path resolver bound to one working directory.
     */
    public function __construct(private string $workingDirectory)
    {
    }

    /**
     * Returns the paths to scan, defaulting to the Composer autoload roots.
     *
     * @param list<string> $requested
     * @return list<string>
     */
    public function resolve(array $requested): array
    {
        $paths = [];

        foreach ($requested === [] ? $this->autoloadPaths() : $requested as $path) {
            $absolute = str_starts_with($path, '/') ? $path : $this->workingDirectory.'/'.rtrim($path, '/');

            if (is_dir($absolute) || is_file($absolute)) {
                $paths[] = $absolute;
            }
        }

        if ($paths === []) {
            $paths[] = $this->workingDirectory;
        }

        sort($paths);

        return $paths;
    }

    /**
     * Returns the directories declared by the Composer autoload configuration.
     *
     * @return list<string>
     */
    public function autoloadPaths(): array
    {
        $manifest = $this->workingDirectory.'/composer.json';
        $contents = is_file($manifest) ? file_get_contents($manifest) : false;
        $decoded = $contents === false ? null : json_decode($contents, true);
        $paths = [];

        if (!is_array($decoded) || !is_array($decoded['autoload'] ?? null)) {
            return ['src'];
        }

        foreach ($decoded['autoload'] as $section => $entries) {
            if (!is_array($entries) || !in_array($section, ['psr-4', 'psr-0', 'classmap'], true)) {
                continue;
            }

            foreach ($entries as $entry) {
                foreach (is_array($entry) ? $entry : [$entry] as $path) {
                    if (is_string($path) && $path !== '') {
                        $paths[] = $path;
                    }
                }
            }
        }

        return $paths === [] ? ['src'] : $paths;
    }

    /**
     * Returns every PHP file below the given paths.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public function files(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[$path] = true;

                continue;
            }

            $directories = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
            $iterator = new RecursiveIteratorIterator(
                $directories,
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if (
                    $file instanceof SplFileInfo
                    && str_ends_with($file->getFilename(), '.php')
                    && !$this->skipped($file->getPathname())
                ) {
                    $files[$file->getPathname()] = true;
                }
            }
        }

        $names = array_keys($files);
        sort($names);

        return $names;
    }

    /**
     * Reports whether a path belongs to a directory that is never scanned.
     */
    public function skipped(string $path): bool
    {
        foreach (self::SKIPPED as $directory) {
            if (str_contains($path, '/'.$directory.'/')) {
                return true;
            }
        }

        return false;
    }
}
