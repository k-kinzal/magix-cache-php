<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use function crc32;

use InvalidArgumentException;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\Ttl;
use Override;
use RuntimeException;

/**
 * Spreads expirations across keys to avoid synchronized expiry.
 *
 * On the normal origin path the strategy overrides the lifetime of
 * the produced metadata, chosen deterministically from the key so that
 * entries written in the same instant expire spread over the declared range
 * instead of together. Knowing the range does not mean the runtime values
 * distribute evenly; the contract only bounds them.
 */
final readonly class KeySpreadExpirationStrategy implements CacheStrategy
{
    /**
     * Creates a key-spread expiration strategy.
     *
     * @param int $minimum Smallest lifetime in seconds the constraint may take.
     * @param int $maximum Largest lifetime in seconds the constraint may take.
     * @throws InvalidArgumentException when the minimum is negative or exceeds the maximum
     */
    public function __construct(
        private int $minimum,
        private int $maximum,
    ) {
        if ($minimum < 0) {
            throw new InvalidArgumentException('The minimum lifetime must be zero or greater.');
        }

        if ($maximum < $minimum) {
            throw new InvalidArgumentException('The maximum lifetime cannot be below the minimum.');
        }
    }

    /**
     * Delegates the lookup unchanged.
     *
     * @return CacheRead<mixed>|null
     * @throws RuntimeException when the delegated read fails with declared behavior
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead
    {
        return $next->get($operation);
    }

    /**
     * Overrides the origin lifetime with the key-derived lifetime.
     *
     * @return OriginResult<mixed>|OriginFailure|CacheAnswer<mixed>
     * @throws RuntimeException when the origin or a delegate fails with declared behavior
     */
    #[Override]
    #[Ttl(min: new ConstructorArg('minimum'), max: new ConstructorArg('maximum'))]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer
    {
        $result = $next->fetch($operation);

        if (!$result instanceof OriginResult) {
            return $result;
        }

        $spread = crc32($operation->key()) % ($this->maximum - $this->minimum + 1);
        return $result->withTtl($this->minimum + $spread);
    }

    /**
     * Delegates the store unchanged.
     *
     * @param CacheWrite<mixed> $result
     * @throws RuntimeException when the delegated write fails with declared behavior
     */
    #[Override]
    public function set(CacheOperation $operation, CacheWrite $result, NextCacheStrategy $next): void
    {
        $next->set($operation, $result);
    }
}
