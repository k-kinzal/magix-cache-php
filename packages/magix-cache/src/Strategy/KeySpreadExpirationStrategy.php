<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Closure;

use function crc32;

use InvalidArgumentException;
use Magix\Cache\Cached;
use Magix\Cache\Clock\SystemClock;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\Ttl;
use Override;
use Psr\Clock\ClockInterface;

/**
 * Spreads expirations across keys to avoid synchronized expiry.
 *
 * On every successful fetch return the strategy overrides the lifetime of
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
        private ClockInterface $clock = new SystemClock(),
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
     * Overrides the result lifetime with the key-derived lifetime.
     *
     * The lifetime starts at this middleware's clock read after delegation.
     * It applies equally to origin values and answers supplied by delegates.
     *
     * Delegated failures propagate unchanged.
     *
     * @return Cached<mixed>
     * @param Closure(): Cached<mixed> $next
     */
    #[Override]
    #[Ttl(min: new ConstructorArg('minimum'), max: new ConstructorArg('maximum'))]
    public function fetch(string $key, Closure $next): Cached
    {
        $result = $next();

        $spread = crc32($key) % ($this->maximum - $this->minimum + 1);
        return Cached::of($result->value(), $result->metadata->withExpiration((float) $this->clock->now()->format('U.u') + ($this->minimum + $spread)));
    }

    /**
     * Delegates the store unchanged.
     *
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
