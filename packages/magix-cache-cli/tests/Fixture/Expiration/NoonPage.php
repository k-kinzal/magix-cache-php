<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Expiration;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Propagates daily expiration constraints through parent cache policies.
 */
final class NoonPage
{
    use Cacheable;

    /**
     * Receives the dependency with a daily expiration window.
     */
    public function __construct(private readonly NoonQuery $query)
    {
    }

    /**
     * @return Cached<string>
     */
    #[Cache]
    public function automatic(): Cached
    {
        return $this->cached(fn (): Cached => $this->query->window());
    }

    /**
     * @return Cached<string>
     */
    #[Cache(ttl: 60)]
    public function fixed(): Cached
    {
        return $this->cached(fn (): Cached => $this->query->window());
    }

    /**
     * @return Cached<string>
     */
    #[Cache(ttl: Ttl::FromUpstream, maxTtl: 30)]
    public function bounded(): Cached
    {
        return $this->cached(fn (): Cached => $this->query->window());
    }

    /**
     * @return Cached<string>
     */
    public function show(): Cached
    {
        return $this->bounded();
    }

    /**
     * @return Cached<string>
     */
    #[Cache]
    public function multipleAutomatic(): Cached
    {
        return $this->cached(fn (): Cached => $this->query->multiple());
    }

    /**
     * @return Cached<string>
     */
    #[Cache(ttl: 30)]
    public function multipleComposed(): Cached
    {
        return $this->cached(fn (): Cached => $this->query->multipleComposed());
    }
}
