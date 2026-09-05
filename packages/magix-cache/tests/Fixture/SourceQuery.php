<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Shares one declared boundary method across concrete subclasses.
 */
abstract class SourceQuery
{
    use Cacheable;

    /**
     * Number of computation executions.
     */
    public int $calls = 0;

    /**
     * Returns a value identified by the concrete class.
     *
     * @return Cached<class-string<static>>
     */
    #[Cache(ttl: 20)]
    public function fetch(): Cached
    {
        return $this->cached(function (): Cached {
            ++$this->calls;

            return Cached::of(static::class);
        });
    }
}
