<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Runtime\Extension\CacheTtlResolver;
use Magix\Cache\Runtime\Extension\DynamicTtlContext;
use Override;

/**
 * Resolves a per-result lifetime for boundaries that declare #[DynamicTtl].
 */
final readonly class FeedTtlResolver implements CacheTtlResolver
{
    /**
     * Returns a lifetime derived from the resolved result.
     */
    #[Override]
    public function resolve(DynamicTtlContext $context): int
    {
        return 30;
    }
}
