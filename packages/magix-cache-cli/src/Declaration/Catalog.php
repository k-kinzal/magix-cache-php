<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

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
     * Boundaries indexed by their fully qualified identifier.
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
     * Creates a catalog from parsed classes.
     *
     * @param list<ClassDeclaration> $classes
     */
    public function __construct(array $classes)
    {
        $index = [];
        $direct = [];

        foreach ($classes as $class) {
            $direct[$class->name] = $class->parents;

            foreach ($class->boundaries as $boundary) {
                $index[$boundary->id()] = $boundary;
            }
        }

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
        return array_values($this->index);
    }

    /**
     * Returns the boundaries a call to the given type and method can reach.
     *
     * @return list<BoundaryDeclaration>
     */
    public function candidates(string $class, string $method): array
    {
        $id = $class.'::'.$method;

        if (array_key_exists($id, $this->index)) {
            return [$this->index[$id]];
        }

        $matches = [];

        foreach ($this->index as $boundary) {
            if ($boundary->method !== $method) {
                continue;
            }

            if (in_array($class, $this->ancestors[$boundary->class] ?? [], true)) {
                $matches[] = $boundary;
            }
        }

        return $matches;
    }

    /**
     * Returns the boundaries matching a user supplied reference.
     *
     * @return list<BoundaryDeclaration>
     */
    public function search(string $reference): array
    {
        $separator = strpos($reference, '::');
        $class = $separator === false ? $reference : substr($reference, 0, $separator);
        $method = $separator === false ? null : substr($reference, $separator + 2);
        $matches = [];

        foreach ($this->index as $boundary) {
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
