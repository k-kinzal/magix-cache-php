<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheAnswer;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\Contract\Ttl;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginFailure;
use Magix\Cache\Strategy\OriginResult;
use Override;

/**
 * Makes execution-local state visible through monotone diagnostic tags.
 */
final class StatefulStrategy implements CacheStrategy
{
    /**
     * Number of lookups in this execution.
     */
    public int $lookups = 0;
    /**
     * Number of fetches in this execution.
     */
    public int $fetches = 0;
    /**
     * Number of stores in this execution.
     */
    public int $stores = 0;
    /**
     * Key seen by this execution during lookup.
     */
    public string $lookupKey = '';

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(public readonly string $label = 'state', public array $settings = [], public readonly ?CacheStrategy $child = null)
    {
    }

    /**
     * @return CacheRead<mixed>|null
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead
    {
        ++$this->lookups;
        $this->lookupKey = $operation->key();

        return ($this->child === null ? $next : $next->prepend($this->child))->get($operation);
    }

    /**
     * @return OriginResult<mixed>|OriginFailure|CacheAnswer<mixed>
     */
    #[Override]
    #[Ttl(unconstrained: true)]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer
    {
        ++$this->fetches;
        $result = ($this->child === null ? $next : $next->prepend($this->child))->fetch($operation);

        return $result instanceof OriginResult
            ? $result->constrain(new CacheMetadata(tags: [$this->label.':'.$this->lookups.':'.$this->fetches, 'lookup:'.$this->lookupKey]))
            : $result;
    }

    /**
     * @param CacheWrite<mixed> $request
     */
    #[Override]
    public function set(CacheOperation $operation, CacheWrite $request, NextCacheStrategy $next): void
    {
        ++$this->stores;
        ($this->child === null ? $next : $next->prepend($this->child))->set($operation, $request);
    }
}
