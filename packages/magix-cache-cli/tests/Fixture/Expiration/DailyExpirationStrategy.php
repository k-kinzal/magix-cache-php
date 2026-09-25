<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Expiration;

use Closure;
use LogicException;
use Magix\Cache\Async\Promise;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\ExpiresAt;
use Magix\Cache\Strategy\StrategyDefinition;
use Override;

/**
 * Analysis-only fixture: reading its contract must never execute fetch().

 */
final readonly class DailyExpirationStrategy implements CacheStrategy
{
    /**
     * Retains values for static construction binding.
     */
    public function __construct(public string $at, public ?string $until, public string $timezone)
    {
    }

    /**
     * Returns the immutable definition without selecting a current occurrence.
     */
    public static function create(string $at = '12:00', ?string $until = null, string $timezone = 'UTC'): StrategyDefinition
    {
        return StrategyDefinition::of(DailyExpirationStrategy::class, $at, $until, $timezone);
    }

    /**
     * Delegated failures propagate unchanged.
     *
     * @return CacheRead<mixed>|null
     * @param Closure(string): (CacheRead<mixed>|null) $next
     */
    #[Override]
    public function get(string $key, Closure $next): ?CacheRead
    {
        return $next($key);
    }

    /**
     * @return Promise<Cached<mixed>>
     * @throws LogicException when this analysis-only fixture is executed
     * @param Closure(): Promise<Cached<mixed>> $next
     */
    #[Override]
    #[ExpiresAt(new ConstructorArg('at'), until: new ConstructorArg('until'), timezone: new ConstructorArg('timezone'))]
    public function fetch(string $key, Closure $next): Promise
    {
        throw new LogicException('The analyzer must not execute the strategy.');
    }

    /**
     * Delegated failures propagate unchanged.
     *
     * @param CacheWrite<mixed> $result
     * @param Closure(string, CacheWrite<mixed>): void $next
     */
    #[Override]
    public function set(string $key, CacheWrite $result, Closure $next): void
    {
        $next($key, $result);
    }

}
