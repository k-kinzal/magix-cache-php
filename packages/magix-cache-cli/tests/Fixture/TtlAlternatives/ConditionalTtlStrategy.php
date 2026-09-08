<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\TtlAlternatives;

use InvalidArgumentException;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheAnswer;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\Ttl;
use Magix\Cache\Strategy\Contract\TtlRange;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginFailure;
use Magix\Cache\Strategy\OriginResult;
use Magix\Cache\Strategy\StrategyDefinition;
use Override;
use RuntimeException;

/**
 * Selects a longer lifetime between midnight and 06:00 UTC.
 */
final readonly class ConditionalTtlStrategy implements CacheStrategy
{
    /**
     * @throws InvalidArgumentException when a lifetime is negative or the range contradicts
     */
    public function __construct(private int $normal, private int $minimum, private int $maximum)
    {
        new TtlRange($normal, $normal);
        new TtlRange($minimum, $maximum);
    }

    /**
     * Describes construction without evaluating the time-dependent choice.
     */
    public static function create(int $normal = 30, int $minimum = 600, int $maximum = 900): StrategyDefinition
    {
        return StrategyDefinition::of(ConditionalTtlStrategy::class, normal: $normal, minimum: $minimum, maximum: $maximum);
    }

    /**
     * @return CacheRead<mixed>|null
     * @throws RuntimeException when the delegated read fails
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead
    {
        return $next->get($operation);
    }

    /**
     * @return OriginResult<mixed>|OriginFailure|CacheAnswer<mixed>
     * @throws RuntimeException when a delegate fails
     */
    #[Override]
    #[Ttl(new ConstructorArg('normal'), new TtlRange(min: new ConstructorArg('minimum'), max: new ConstructorArg('maximum')))]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer
    {
        $result = $next->fetch($operation);

        if (!$result instanceof OriginResult) {
            return $result;
        }

        $ttl = (int) $result->baseTime % 86400 < 21600
            ? $this->minimum + crc32($operation->key()) % ($this->maximum - $this->minimum + 1)
            : $this->normal;

        return $result->constrain(CacheMetadata::forTtl($ttl, $result->baseTime));
    }

    /**
     * @param CacheWrite<mixed> $result
     * @throws RuntimeException when the delegated write fails
     */
    #[Override]
    public function set(CacheOperation $operation, CacheWrite $result, NextCacheStrategy $next): void
    {
        $next->set($operation, $result);
    }
}
