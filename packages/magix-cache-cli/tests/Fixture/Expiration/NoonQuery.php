<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Expiration;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\StrategyArgument;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Declares daily points, windows, and invocation-bound clock values.
 */
#[Cache]
final class NoonQuery
{
    use Cacheable;

    /**
     * @return Cached<string>
     */
    #[UseStrategy(DailyExpirationStrategy::class, at: '12:00', until: '12:15', timezone: 'Asia/Tokyo')]
    public function window(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('value'));
    }

    /**
     * @return Cached<string>
     */
    #[UseStrategy(DailyExpirationStrategy::class)]
    public function single(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('value'));
    }

    /**
     * @return Cached<string>
     */
    #[UseStrategy(DailyExpirationStrategy::class, at: '23:55:30', until: '00:10:15')]
    public function overnight(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('value'));
    }

    /**
     * @return Cached<string>
     */
    #[UseStrategy(DailyExpirationStrategy::class, until: '12:15', timezone: 'Asia/Tokyo')]
    public function dynamic(#[StrategyArgument('at')] string $cutoff = '12:00'): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('value'));
    }

    /**
     * @return Cached<string>
     */
    #[UseStrategy(DailyComposition::class)]
    public function composed(): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('value'));
    }
}
