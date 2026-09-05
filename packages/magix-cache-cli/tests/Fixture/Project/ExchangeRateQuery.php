<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Loads rates whose lifetime a registered resolver shortens per result.
 */
final class ExchangeRateQuery
{
    use Cacheable;

    /**
     * Returns the rate of one currency pair.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: 60, tags: ['rates'], runtime: 'edge')]
    #[DynamicTtl(resolver: FeedTtlResolver::class)]
    public function execute(string $pair): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of(1));
    }
}
