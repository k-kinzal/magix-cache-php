<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Console;

use function array_filter;
use function array_values;
use function is_string;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\ConstantCatalog;
use Magix\Cache\Cli\Source\SourceParser;
use Magix\Cache\Cli\Source\SourcePaths;

use function str_starts_with;
use function strlen;
use function substr;

/**
 * Builds the boundary catalog that every command works on.
 *
 * Constants are collected from every scanned file first, so a policy that
 * references a constant declared elsewhere reads exactly like a literal one.
 */
final readonly class CatalogLoader
{
    /**
     * Creates a catalog loader bound to one working directory.
     */
    public function __construct(
        private string $workingDirectory,
        private SourceParser $parser = new SourceParser(),
    ) {
    }

    /**
     * Returns the catalog for the requested paths or the Composer autoload roots.
     *
     * @param array<array-key, mixed> $paths
     */
    public function load(array $paths): Catalog
    {
        $requested = array_values(array_filter($paths, is_string(...)));
        $sources = new SourcePaths($this->workingDirectory);
        $files = $sources->files($sources->resolve($requested));
        $constants = new ConstantCatalog();

        foreach ($files as $file) {
            $constants = $constants->merge($this->parser->constants($file));
        }

        $prefix = $this->workingDirectory.'/';
        $classes = [];

        foreach ($files as $file) {
            $display = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;

            foreach ($this->parser->parse($file, $display, $constants) as $class) {
                $classes[] = $class;
            }
        }

        return new Catalog($classes);
    }
}
