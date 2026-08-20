<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Runtime\Metadata\Visibility;

/**
 * Holds the constraints that the dependencies of a boundary impose on it.
 */
final readonly class DependencyConstraint
{
    /**
     * Creates a dependency constraint.
     *
     * @param list<string> $tags
     */
    public function __construct(
        public ?int $ttl = null,
        public ?string $ttlSource = null,
        public Visibility $visibility = Visibility::Shared,
        public ?string $visibilitySource = null,
        public array $tags = [],
    ) {
    }
}
