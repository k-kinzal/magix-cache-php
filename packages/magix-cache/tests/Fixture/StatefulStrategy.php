<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Closure;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
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
     * @param Closure(string): (CacheRead<mixed>|null) $next
     */
    #[Override]
    public function get(string $key, Closure $next): ?CacheRead
    {
        ++$this->lookups;
        $this->lookupKey = $key;

        return $this->child === null ? $next($key) : $this->child->get($key, $next);
    }

    /**
     * @return Cached<mixed>
     * @param Closure(): Cached<mixed> $next
     */
    #[Override]
    public function fetch(string $key, Closure $next): Cached
    {
        ++$this->fetches;
        $result = $this->child === null ? $next() : $this->child->fetch($key, $next);

        return Cached::of($result->value(), $result->metadata->withTags([...$result->metadata->tags, $this->label.':'.$this->lookups.':'.$this->fetches, 'lookup:'.$this->lookupKey]));
    }

    /**
     * @param CacheWrite<mixed> $request
     * @param Closure(string, CacheWrite<mixed>): void $next
     */
    #[Override]
    public function set(string $key, CacheWrite $request, Closure $next): void
    {
        ++$this->stores;
        if ($this->child === null) {
            $next($key, $request);
        } else {
            $this->child->set($key, $request, $next);
        }
    }

}
