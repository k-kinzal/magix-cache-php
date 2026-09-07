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
     */
    public function __construct(
        ?TtlEstimate $ttl = null,
        public ?string $ttlSource = null,
        public Visibility $visibility = Visibility::Shared,
        public ?string $visibilitySource = null,
        public array $tags = [],
        public bool $visibilityUnknown = false,
        public bool $tagsUnknown = false,
    ) {
        $this->ttl = $ttl ?? TtlEstimate::unconstrained();
    }
}
