<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Observation\CacheEvent;
use Magix\Cache\Runtime\CacheExecution;
use Magix\Cache\Runtime\GuardedCache;
use Magix\Cache\Strategy\CacheWrite;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\RecordingObserver;
use Tests\Fixture\UpstreamUnavailable;

#[CoversClass(CacheExecution::class)]
#[UsesClass(\Magix\Cache\Runtime\Extension\DynamicTtlContext::class)]
#[UsesClass(StaleIfError::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CachePolicy::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Runtime\CacheEntryConverter::class)]
#[UsesClass(GuardedCache::class)]
#[UsesClass(\Magix\Cache\Runtime\BoundaryMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Policy\PolicySemantics::class)]
#[UsesClass(CacheWrite::class)]
#[UsesClass(\Magix\Cache\Strategy\CacheRead::class)]
#[UsesNamespace('Magix\Cache')]
final class CacheExecutionTest extends TestCase
{
    public function testGetExposesAStoredFreshCandidate(): void
    {
        $storage = new MemoryCache();
        $storage->set('key', new CacheEntry('stored', new CacheMetadata(expiresAt: 150.0)));
        $execution = new CacheExecution(
            cache: new GuardedCache($storage),
            classifier: null,
            clock: new \Magix\Cache\Clock\UnixClock(new \Tests\Fixture\MutableClock(100.0)),
            origin: static fn (): Cached => Cached::of('origin'),
        );
        $key = 'key';

        self::assertSame('stored', $execution->get($key)?->cached->value());
    }

    public function testGetRetainsAnExpiredEntryStillInsideItsRetention(): void
    {
        $storage = new MemoryCache();
        $storage->set('key', new CacheEntry('stale', new CacheMetadata(expiresAt: 90.0), retainedUntil: 400.0));
        $execution = new CacheExecution(
            cache: new GuardedCache($storage),
            classifier: null,
            clock: new \Magix\Cache\Clock\UnixClock(new \Tests\Fixture\MutableClock(100.0)),
            origin: static fn (): Cached => Cached::of('origin'),
        );
        $key = 'key';

        $read = $execution->get($key);
        self::assertSame('stale', $read?->cached->value());
        self::assertSame(90.0, $read->cached->metadata->expiresAt);
        self::assertSame(400.0, $read->retainedUntil);
    }

    public function testFetchInvokesTheOriginWithoutArguments(): void
    {
        $calls = 0;
        $execution = new CacheExecution(
            cache: new GuardedCache(new MemoryCache()),
            classifier: null,
            clock: new \Magix\Cache\Clock\UnixClock(new \Tests\Fixture\MutableClock(100.0)),
            origin: static function () use (&$calls): Cached {
                ++$calls;

                return Cached::of(func_num_args());
            },
            policy: new CachePolicy(ttl: 30),
        );
        $key = 'key';

        $result = $execution->fetch($key)->wait();

        self::assertSame(0, $result->value());
        self::assertSame(130.0, $result->metadata->expiresAt);
        self::assertSame(1, $calls);
    }

    public function testSetWritesTheRequestedRetention(): void
    {
        $storage = new MemoryCache();
        $execution = new CacheExecution(
            cache: new GuardedCache($storage),
            classifier: null,
            clock: new \Magix\Cache\Clock\UnixClock(new \Tests\Fixture\MutableClock(100.0)),
            origin: static fn (): Cached => Cached::of('origin'),
        );
        $key = 'key';

        $execution->set($key, new CacheWrite(Cached::of('value', new CacheMetadata(expiresAt: 160.0)), 500.0));

        $entry = $storage->get('key', static fn (): string => 'value');

        self::assertSame(500.0, $entry?->retainedUntil);
        self::assertSame(160.0, $entry->expiresAt);
    }

    public function testSetSkipsAnUnstorableResult(): void
    {
        $observer = new RecordingObserver();
        $execution = new CacheExecution(
            cache: new GuardedCache(new MemoryCache(), $observer),
            classifier: null,
            clock: new \Magix\Cache\Clock\UnixClock(new \Tests\Fixture\MutableClock(100.0)),
            origin: static fn (): Cached => Cached::of('origin'),
            observer: $observer,
        );
        $key = 'key';

        $execution->set($key, new CacheWrite(Cached::of('value')));

        self::assertSame([CacheEvent::StoreSkipped], $observer->events);
    }


    public function testFetchPropagatesTheOriginalException(): void
    {
        $error = new UpstreamUnavailable('down');
        $execution = new CacheExecution(new GuardedCache(new MemoryCache()), null, static fn (): Cached => throw $error, new \Magix\Cache\Clock\UnixClock(new \Tests\Fixture\MutableClock(100.0)));

        $this->expectExceptionObject($error);
        $execution->fetch('key')->wait();
    }
    public function testFetchAppliesLocalSettingsAtOneTimeAfterTheOriginReturns(): void
    {
        $clock = new \Tests\Fixture\MutableClock(100.0);
        $resolver = new class ($clock) implements \Magix\Cache\Runtime\Extension\CacheTtlResolver {
            public function __construct(private readonly \Tests\Fixture\MutableClock $clock)
            {
            }

            #[Override]
            public function resolve(\Magix\Cache\Runtime\Extension\DynamicTtlContext $context): int
            {
                $this->clock->advance(17.0);

                return 7;
            }
        };
        $execution = new CacheExecution(
            new GuardedCache(new MemoryCache()),
            null,
            static function () use ($clock): Cached {
                $clock->advance(2.25);

                return Cached::of('value', new CacheMetadata(tags: ['dependency']));
            },
            clock: new \Magix\Cache\Clock\UnixClock($clock),
            policy: new CachePolicy(ttl: 100),
            ttlResolver: $resolver,
            parameterTtl: 90,
        );

        $result = $execution->fetch('key')->wait();

        self::assertSame(119.25, $clock->time);
        self::assertSame(109.25, $result->metadata->expiresAt);
        self::assertSame(['dependency'], $result->metadata->tags);
    }
}
