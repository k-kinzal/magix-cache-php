<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Runtime\Extension\CacheTtlResolver;
use Magix\Cache\Runtime\Extension\DynamicTtlContext;
use Override;

/**
 * Resolves every result to one configured lifetime.
 */
final class FixedTtlResolver implements CacheTtlResolver
{
    /**
     * Number of resolutions performed.
     */
    public int $calls = 0;

    /**
     * Creates a resolver that always answers the supplied lifetime.
     */
    public function __construct(private readonly int $seconds = 7)
    {
    }

    /**
     * Returns the configured lifetime for any result.
     */
    #[Override]
    public function resolve(DynamicTtlContext $context): int
    {
        unset($context);
        ++$this->calls;

        return $this->seconds;
    }
}
