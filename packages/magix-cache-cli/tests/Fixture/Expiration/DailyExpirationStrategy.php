<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Expiration;

use LogicException;
use Magix\Cache\Strategy\CacheAnswer;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\ExpiresAt;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginFailure;
use Magix\Cache\Strategy\OriginResult;
use Magix\Cache\Strategy\StrategyDefinition;
use Override;
use RuntimeException;

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
     * @return CacheRead<mixed>|null
     * @throws RuntimeException when a delegated read fails
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead
    {
        return $next->get($operation);
    }

    /**
     * @return OriginResult<mixed>|OriginFailure|CacheAnswer<mixed>
     * @throws LogicException when this analysis-only fixture is executed
     */
    #[Override]
    #[ExpiresAt(new ConstructorArg('at'), until: new ConstructorArg('until'), timezone: new ConstructorArg('timezone'))]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer
    {
        throw new LogicException('The analyzer must not execute the strategy.');
    }

    /**
     * @param CacheWrite<mixed> $result
     * @throws RuntimeException when a delegated write fails
     */
    #[Override]
    public function set(CacheOperation $operation, CacheWrite $result, NextCacheStrategy $next): void
    {
        $next->set($operation, $result);
    }
}
