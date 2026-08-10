<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheKey;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Metadata\Visibility;

/**
 * Declares representative argument attributes for key tests.
 */
final readonly class KeyQuery
{
    /**
     * Returns the arguments as a cache-aware fixture value.
     *
     * @param mixed ...$rest
     * @return Cached<array{int, string, list<mixed>}>
     */
    #[Cache(ttl: 30, version: 'key-query')]
    public function execute(
        #[CacheKey([Reducer::class, 'parity'])]
        #[CacheScope(Visibility::Private)]
        int $viewer,
        #[CacheIgnore]
        string $trace = '',
        mixed ...$rest,
    ): Cached {
        return Cached::of([$viewer, $trace, array_values($rest)]);
    }
}
