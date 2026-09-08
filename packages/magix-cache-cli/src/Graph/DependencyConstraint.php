<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Metadata\Visibility;

/**
 * Holds the constraints that the dependencies of a boundary impose on it.
 */
final readonly class DependencyConstraint
{
    /**
     * The lifetime constraint the dependencies combine to.
     */
    public TtlEstimate $ttl;

    /**
     * Creates a dependency constraint.
     *
     * @param TtlEstimate|null $ttl Defaults to Unconstrained: no dependency imposes an expiration.
     * @param list<string> $tags
     * @param bool $hasDependencies Whether any dependency nodes were actually expanded, distinguishing composition from a leaf or depth cutoff.
     * @param list<ExpirationEstimate> $expirationConstraints Daily candidates that may only be shortened by further composition.
     */
    public function __construct(
        ?TtlEstimate $ttl = null,
        public ?string $ttlSource = null,
        public Visibility $visibility = Visibility::Shared,
        public ?string $visibilitySource = null,
        public array $tags = [],
        public bool $visibilityUnknown = false,
        public bool $tagsUnknown = false,
        public bool $hasDependencies = false,
        public array $expirationConstraints = [],
    ) {
        $this->ttl = $ttl ?? TtlEstimate::unconstrained();
    }
}
