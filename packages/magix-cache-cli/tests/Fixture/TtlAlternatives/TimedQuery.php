<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\TtlAlternatives;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Derives its expiration from the time-dependent strategy.
 */
#[Cache]
#[UseStrategy(strategy: ConditionalTtlStrategy::class)]
final class TimedQuery
{
    use Cacheable;

    /**
     * @return Cached<string>
     */
    public function execute(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('value'));
    }
}
