<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Cache\PSR16\SimpleCache;
use Magix\Cache\Cache\PSR6\CacheItemPool;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Runs the same declaration graph against controlled storage contents and time.
 */
final class MetadataScenario
{
    /**
     * Fixed fractional instant beyond backend wall-clock expiration checks.
     */
    public const float BASE_TIME = 4_000_000_000.25;

    /**
     * Distinct payloads identify the seven graph boundaries in stored entries.
     */
    public const array NODE_VALUES = [
        'leaf:0' => 'leaf:0',
        'leaf:1' => 'leaf:1',
        'left' => 'leaf:0,leaf:1',
        'leaf:2' => 'leaf:2',
        'leaf:3' => 'leaf:3',
        'right' => 'leaf:1,leaf:2,leaf:3',
        'root' => 'leaf:0,leaf:1|leaf:1,leaf:2,leaf:3',
    ];

    /**
     * Cache currently connected to the runtime.
     */
    public RecordingCache $cache;

    /**
     * Clock shared by the runtime and relative-TTL storage adapter.
     */
    public readonly MutableClock $clock;

    /**
     * Resolver whose calls distinguish origin evaluation from reuse.
     */
    public readonly FixedTtlResolver $resolver;

    /**
     * The attributed public boundaries being exercised.
     */
    public readonly MetadataGraph $graph;

    /**
     * Creates an isolated scenario with a stable source and declaration.
     *
     * @param 'memory'|'psr6'|'psr16' $backend
     */
    public function __construct(
        private readonly string $backend,
        CacheMetadata $source,
        int $ttl = 20,
        Visibility $visibility = Visibility::Shared,
    ) {
        $this->clock = new MutableClock(self::BASE_TIME);
        $this->resolver = new FixedTtlResolver(50);
        $this->graph = new MetadataGraph($source, $ttl, $visibility);
        $this->cache = $this->emptyCache();
        $this->activate();
    }

    /**
     * Creates an empty backend; both PSR variants serialize stored values.
     */
    public function emptyCache(): RecordingCache
    {
        return new RecordingCache(match ($this->backend) {
            'memory' => new MemoryCache(),
            'psr6' => new CacheItemPool(new ArrayAdapter(storeSerialized: true)),
            'psr16' => new SimpleCache(new Psr16Cache(new ArrayAdapter(storeSerialized: true)), $this->clock),
        });
    }

    /**
     * Connects the scenario's current backend to a new runtime.
     */
    public function activate(): void
    {
        CacheRuntimeRegistry::reset();
        CacheRuntimeRegistry::register('default', new CacheRuntime($this->cache, $this->clock, ttlResolvers: [$this->resolver]));
    }

    /**
     * Retains a subset of the last recorded writes and starts a new execution.
     *
     * @return array<string, true> Retained boundary names.
     */
    public function retain(int $mask): array
    {
        $entries = $this->cache->entries;
        $this->cache = $this->emptyCache();
        $nodes = [];
        $index = 0;

        foreach ($entries as $key => $entry) {
            if (($mask & (1 << $index)) !== 0) {
                $this->cache->set($key, $entry);
                $node = array_search($entry->value(), self::NODE_VALUES, true);
                if ($node !== false) {
                    $nodes[$node] = true;
                }
            }
            ++$index;
        }

        $this->cache->entries = [];
        $this->graph->calls = [];
        $this->activate();

        return $nodes;
    }

    /**
     * Retains named boundaries without depending on their insertion order.
     *
     * @param list<string> $names
     */
    public function retainNodes(array $names): void
    {
        $mask = 0;
        $index = 0;
        foreach ($this->cache->entries as $entry) {
            if (in_array(array_search($entry->value(), self::NODE_VALUES, true), $names, true)) {
                $mask |= 1 << $index;
            }
            ++$index;
        }
        $this->retain($mask);
    }
}
