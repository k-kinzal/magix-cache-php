<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\TtlAlternatives;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Applies parent policies to a time-dependent cache boundary.
 */
final class TimedPage
{
    use Cacheable;

    /**
     * Receives the dependency whose alternative lifetimes propagate.
     */
    public function __construct(private readonly TimedQuery $query)
    {
    }

    /**
     * @return Cached<string>
     */
    #[Cache]
    public function automatic(): Cached
    {
        return $this->cached(fn (): Cached => $this->query->execute());
    }

    /**
     * @return Cached<string>
     */
    #[Cache(ttl: Ttl::FromUpstream, maxTtl: 700)]
    public function bounded(): Cached
    {
        return $this->cached(fn (): Cached => $this->query->execute());
    }

    /**
     * @return Cached<string>
     */
    #[Cache(ttl: 300)]
    public function fixed(): Cached
    {
        return $this->cached(fn (): Cached => $this->query->execute());
    }

    /**
     * @return Cached<string>
     */
    #[Cache(ttl: 20)]
    public function shorter(): Cached
    {
        return $this->cached(fn (): Cached => $this->query->execute());
    }

    /**
     * @return Cached<string>
     */
    public function show(): Cached
    {
        return $this->bounded();
    }
}
