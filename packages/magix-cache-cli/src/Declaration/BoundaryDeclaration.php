<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

use Magix\Cache\Metadata\Visibility;

use function strrpos;
use function substr;

/**
 * Holds an analyzed method and the declarations around its cached() call, if any.
 */
final readonly class BoundaryDeclaration
{
    /**
     * Creates a method declaration for a cache boundary or an uncached analysis entry point.
     *
     * @param list<KeyParameter> $parameters
     * @param list<DependencyCall> $dependencies
     * @param bool $hasDynamicTtl Whether an enabled #[DynamicTtl] applies to the boundary.
     * @param bool $suppliesMetadata Whether the boundary builds CacheMetadata itself.
     * @param UseStrategyDeclaration|null $useStrategy The enabled #[UseStrategy] applying to the boundary.
     * @param bool $isCacheBoundary False for an uncached analysis entry point, whose dependencies alone supply constraints.
     */
    public function __construct(
        public string $class,
        public string $method,
        public string $file,
        public int $line,
        public ?PolicyDeclaration $policy = null,
        public array $parameters = [],
        public array $dependencies = [],
        public bool $hasDynamicTtl = false,
        public bool $suppliesMetadata = false,
        public ?UseStrategyDeclaration $useStrategy = null,
        public bool $isCacheBoundary = true,
    ) {
    }

    /**
     * Returns the fully qualified boundary identifier.
     */
    public function id(): string
    {
        return $this->class.'::'.$this->method;
    }

    /**
     * Returns the boundary identifier without its namespace.
     */
    public function shortId(): string
    {
        $separator = strrpos($this->class, '\\');
        $class = $separator === false ? $this->class : substr($this->class, $separator + 1);

        return $class.'::'.$this->method;
    }

    /**
     * Returns the visibility that scoped parameters impose on this boundary.
     */
    public function scope(): ?Visibility
    {
        $visibility = null;

        foreach ($this->parameters as $parameter) {
            if ($parameter->scope !== null) {
                $visibility = $parameter->scope;
            }
        }

        return $visibility;
    }
}
