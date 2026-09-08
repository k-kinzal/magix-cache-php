<?php

declare(strict_types=1);

namespace Tests\Unit;

use Magix\Cache\Cached;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Override;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\CachedQuery;
use Tests\Fixture\FailingCache;
use Tests\Fixture\FirstSourceQuery;
use Tests\Fixture\FixedTtlResolver;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\MutableClock;
use Tests\Fixture\ParameterQuery;
use Tests\Fixture\ParameterStrategy;
use Tests\Fixture\SecondSourceQuery;
use Tests\Fixture\StrategyQuery;

#[CoversTrait(\Magix\Cache\Cacheable::class)]
#[UsesNamespace('Magix\Cache')]
final class CacheableTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        CacheRuntimeRegistry::reset();
        ParameterStrategy::$factories = 0;
    }

    #[Override]
    protected function tearDown(): void
    {
        CacheRuntimeRegistry::reset();
        ParameterStrategy::$factories = 0;
    }

    public function testCachedRunsTheDeclaredStrategyComposition(): void
    {
        CacheRuntimeRegistry::register(
            CacheRuntimeRegistry::DEFAULT_NAME,
            new CacheRuntime(new MemoryCache(), new MutableClock(4_000_000_000.0)),
        );
        $query = new StrategyQuery();

        $pinned = $query->viaMethod(1);
        $spread = $query->viaClass(1);
        $plain = $query->withoutStrategy(1);

        self::assertSame(4_000_000_060.0, $pinned->metadata->expiresAt, 'min: 60 pins the declared spread');
        self::assertNotNull($spread->metadata->expiresAt);
        self::assertGreaterThanOrEqual(4_000_000_030.0, $spread->metadata->expiresAt);
        self::assertLessThanOrEqual(4_000_000_060.0, $spread->metadata->expiresAt);
        self::assertSame(4_000_000_060.0, $plain->metadata->expiresAt, 'a disabled declaration leaves only the fixed policy');
    }

    public function testCachedResolvesTheDeclarationAndReusesTheStoredEntry(): void
    {
        CacheRuntimeRegistry::register(
            CacheRuntimeRegistry::DEFAULT_NAME,
            new CacheRuntime(new MemoryCache(), new MutableClock(4_000_000_000.0)),
        );
        $query = new CachedQuery();

        $first = $query->execute(1, 'trace-a');
        $second = $query->execute(1, 'trace-b');

        self::assertSame('1:trace-a', $first->value());
        self::assertEquals($first, $second);
        self::assertSame(1, $query->calls);
    }

    public function testCachedSeparatesEntriesByConcreteClass(): void
    {
        CacheRuntimeRegistry::register(
            CacheRuntimeRegistry::DEFAULT_NAME,
            new CacheRuntime(new MemoryCache(), new MutableClock(4_000_000_000.0)),
        );
        $first = new FirstSourceQuery();
        $second = new SecondSourceQuery();

        self::assertSame(FirstSourceQuery::class, $first->fetch()->value());
        self::assertSame(SecondSourceQuery::class, $second->fetch()->value());
        self::assertSame(1, $first->calls);
        self::assertSame(1, $second->calls);
    }

    public function testBareCacheAttributePropagatesDependencyMetadata(): void
    {
        $now = 4_000_000_000.0;
        CacheRuntimeRegistry::register(
            CacheRuntimeRegistry::DEFAULT_NAME,
            new CacheRuntime(new MemoryCache(), new MutableClock($now)),
        );
        $dependency = Cached::of('dependency', new CacheMetadata(
            expiresAt: $now + 15.0,
            visibility: Visibility::Private,
            tags: ['dependency'],
            reasons: ['from-child'],
        ));

        $result = (new CachedQuery())->auto($dependency);

        self::assertSame('auto', $result->value());
        self::assertEquals($dependency->metadata, $result->metadata);
    }

    public function testCachedAppliesTheDeclaredDynamicTtlResolver(): void
    {
        $now = 4_000_000_000.0;
        CacheRuntimeRegistry::register(
            CacheRuntimeRegistry::DEFAULT_NAME,
            new CacheRuntime(
                new MemoryCache(),
                new MutableClock($now),
                ttlResolvers: [new FixedTtlResolver(7)],
            ),
        );
        $query = new CachedQuery();

        $first = $query->dynamic(1);
        $second = $query->dynamic(1);

        self::assertSame($now + 7.0, $first->metadata->expiresAt);
        self::assertEquals($first, $second);
        self::assertSame(1, $query->calls);
    }

    public function testCachedSkipsStorageForNoStoreScope(): void
    {
        CacheRuntimeRegistry::register(
            CacheRuntimeRegistry::DEFAULT_NAME,
            new CacheRuntime(new MemoryCache(), new MutableClock(100.0)),
        );
        $query = new CachedQuery();

        $first = $query->noStore(static fn (): string => 'first');
        $second = $query->noStore(static fn (): string => 'second');

        self::assertSame('first', $first->value());
        self::assertSame('second', $second->value());
        self::assertSame(2, $query->calls);
    }

    public function testCachedSeparatesPrivateEntriesByScopedParameter(): void
    {
        CacheRuntimeRegistry::register(
            CacheRuntimeRegistry::DEFAULT_NAME,
            new CacheRuntime(new MemoryCache(), new MutableClock(100.0)),
        );
        $query = new CachedQuery();

        $firstUser = $query->personal(1);
        $firstUserAgain = $query->personal(1);
        $secondUser = $query->personal(2);

        self::assertSame('personal:1', $firstUser->value());
        self::assertSame(Visibility::Private, $firstUser->metadata->visibility);
        self::assertEquals($firstUser, $firstUserAgain);
        self::assertSame('personal:2', $secondUser->value());
        self::assertSame(2, $query->calls);
    }

    public function testCachedBindsDefaultsNamesAndMetadataConstraints(): void
    {
        CacheRuntimeRegistry::register('default', new CacheRuntime(new MemoryCache(), new MutableClock(100.0)));
        $query = new ParameterQuery();
        $default = $query->fetch();
        $same = $query->fetch(visibility: Visibility::Shared, tags: [], ttl: 30);
        $restricted = $query->fetch(tags: ['dynamic', 'static'], visibility: Visibility::Private, ttl: 90);

        self::assertEquals($default, $same);
        self::assertSame(130.0, $default->metadata->expiresAt);
        self::assertSame(160.0, $restricted->metadata->expiresAt);
        self::assertSame(['dynamic', 'static'], $restricted->metadata->tags);
        self::assertSame(Visibility::Private, $restricted->metadata->visibility);
        self::assertSame(2, $query->calls);
    }

    public function testCachedParameterTtlSuppliesAutoAndZeroPreventsStorage(): void
    {
        $cache = new MemoryCache();
        CacheRuntimeRegistry::register('default', new CacheRuntime($cache, new MutableClock(100.0)));
        $query = new ParameterQuery();

        self::assertSame(120.0, $query->auto(20)->metadata->expiresAt);
        self::assertSame(100.0, $query->auto(0)->metadata->expiresAt);
        $query->auto(0);
        self::assertSame(3, $query->calls);
    }

    public function testCachedParameterNoStorePreventsLookupAndStorage(): void
    {
        $cache = new FailingCache();
        CacheRuntimeRegistry::register('default', new CacheRuntime($cache, new MutableClock(100.0)));
        $query = new ParameterQuery();
        $first = $query->fetch(visibility: Visibility::NoStore);
        $second = $query->fetch(visibility: Visibility::NoStore);

        self::assertSame(Visibility::NoStore, $first->metadata->visibility);
        self::assertSame(2, $second->value());
        self::assertSame(2, $query->calls);
    }

    public function testCachedParameterTtlsMeetDependenciesAndEachOther(): void
    {
        CacheRuntimeRegistry::register('default', new CacheRuntime(new MemoryCache(), new MutableClock(100.0)));
        $query = new ParameterQuery();
        $dependency = Cached::of('dependency', new CacheMetadata(expiresAt: 120.0));

        self::assertSame(120.0, $query->composed($dependency, 90)->metadata->expiresAt);
        self::assertSame(105.0, $query->composed($dependency, 90, 5)->metadata->expiresAt);
    }

    public function testCachedStrategyArgumentsRebuildOnHitsAndSurviveKeyReduction(): void
    {
        CacheRuntimeRegistry::register('default', new CacheRuntime(new MemoryCache(), new MutableClock(100.0)));
        $query = new ParameterQuery();
        $first = $query->strategy(30);
        $hit = $query->strategy();
        $different = $query->strategy(10);

        self::assertEquals($first, $hit);
        self::assertSame(130.0, $first->metadata->expiresAt);
        self::assertSame(110.0, $different->metadata->expiresAt);
        self::assertSame(2, $query->calls);
        self::assertSame(3, ParameterStrategy::$factories);
        self::assertContains('bound:1:1', $different->metadata->tags);
    }

    public function testCachedStrategyBindingsAreIndependentAcrossNestedCalls(): void
    {
        CacheRuntimeRegistry::register('default', new CacheRuntime(new MemoryCache(), new MutableClock(100.0)));
        $query = new ParameterQuery();
        $outer = $query->nested(60);
        $subsequent = $query->nested(30, true);
        $both = $query->both(15);

        self::assertSame(110.0, $outer->metadata->expiresAt);
        self::assertSame(130.0, $subsequent->metadata->expiresAt);
        self::assertSame(115.0, $both->metadata->expiresAt);
        self::assertContains('both:1:1', $both->metadata->tags);
    }
}
