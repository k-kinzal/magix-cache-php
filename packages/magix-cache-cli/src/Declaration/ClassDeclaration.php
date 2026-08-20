<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Holds one parsed class together with the boundaries it declares.
 */
final readonly class ClassDeclaration
{
    /**
     * Creates a class declaration.
     *
     * @param list<string> $parents Directly extended and implemented type names.
     * @param list<BoundaryDeclaration> $boundaries
     */
    public function __construct(
        public string $name,
        public array $parents = [],
        public array $boundaries = [],
    ) {
    }
}
