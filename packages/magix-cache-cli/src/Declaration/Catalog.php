<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_shift;
use function in_array;
use function ksort;
use function str_ends_with;
use function strpos;
use function substr;

/**
 * Indexes every parsed class so boundaries and their callees can be found.
 */
final readonly class Catalog
{
    /**
     * Boundaries and uncached entry points indexed by their fully qualified identifier.
     *
     * @var array<string, BoundaryDeclaration>
     */
    private array $index;

    /**
     * Transitively extended and implemented type names per class.
     *
     * @var array<string, list<string>>
     */
    private array $ancestors;

    /**
     * Strategy declarations indexed by their class name.
     *
     * @var array<string, StrategyDeclaration>
     */
    private array $strategies;

    /**
     * Creates a catalog from parsed classes.
     *
     * @param list<ClassDeclaration> $classes
     */
    public function __construct(array $classes)
    {
        $index = [];
        $direct = [];
        $strategies = [];

        foreach ($classes as $class) {
            $direct[$class->name] = $class->parents;

            if ($class->strategy !== null) {
                $strategies[$class->name] = $class->strategy;
            }

            foreach ([...$class->entryPoints, ...$class->boundaries] as $boundary) {
                $index[$boundary->id()] = $boundary;
            }
        }

        $this->strategies = $strategies;

        $ancestors = [];

        foreach ($direct as $name => $parents) {
            $seen = [];
            $queue = $parents;

            while ($queue !== []) {
                $current = array_shift($queue);

                if (array_key_exists($current, $seen)) {
                    continue;
                }

                $seen[$current] = true;

                foreach ($direct[$current] ?? [] as $parent) {
                    $queue[] = $parent;
                }
            }

            $ancestors[$name] = array_keys($seen);
        }

        ksort($index);
        $this->index = $index;
        $this->ancestors = $ancestors;
    }

    /**
     * Returns every declared cache boundary sorted by identifier.
     *
     * @return list<BoundaryDeclaration>
     */
    public function boundaries(): array
    {
        return array_values(array_filter($this->index, static fn (BoundaryDeclaration $boundary): bool => $boundary->isCacheBoundary));
    }

    /**
     * Returns the parsed strategy declaration of one class, when any.
     */
    public function strategy(string $class): ?StrategyDeclaration
    {
        return $this->strategies[$class] ?? null;
    }

    /**
     * Returns the boundaries a call to the given type and method can reach.
     *
     * @param bool $includeEntryPoints Include uncached callees when tracing an analysis entry point.
     * @return list<BoundaryDeclaration>
     */
    public function candidates(string $class, string $method, bool $includeEntryPoints = false): array
    {
        $id = $class.'::'.$method;

        if (array_key_exists($id, $this->index)) {
            $found = $this->index[$id];

            return $found->isCacheBoundary || $includeEntryPoints ? [$found] : [];
        }

        $matches = [];

        foreach ($this->index as $boundary) {
            if ($boundary->method !== $method || (!$boundary->isCacheBoundary && !$includeEntryPoints)) {
                continue;
            }

            if (in_array($class, $this->ancestors[$boundary->class] ?? [], true)) {
                $matches[] = $boundary;
            }
        }

        return $matches;
    }

    /**
     * Returns matching boundaries, optionally including uncached analysis entry points.
     *
     * @param bool $includeEntryPoints Allow analyze to select uncached methods; other commands only select cache boundaries.
     * @return list<BoundaryDeclaration>
     */
    public function search(string $reference, bool $includeEntryPoints = false): array
    {
        $separator = strpos($reference, '::');
        $class = $separator === false ? $reference : substr($reference, 0, $separator);
        $method = $separator === false ? null : substr($reference, $separator + 2);
        $matches = [];

        foreach ($this->index as $boundary) {
            if (!$boundary->isCacheBoundary && !$includeEntryPoints) {
                continue;
            }

            if ($method !== null && $method !== '' && $boundary->method !== $method) {
                continue;
            }

            if ($boundary->class === $class || str_ends_with($boundary->class, '\\'.$class)) {
                $matches[] = $boundary;
            }
        }

        return $matches;
    }
}
