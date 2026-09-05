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
use Tests\Fixture\FirstSourceQuery;
use Tests\Fixture\FixedTtlResolver;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\MutableClock;
use Tests\Fixture\SecondSourceQuery;

#[CoversTrait(\Magix\Cache\Cacheable::class)]
#[UsesNamespace('Magix\Cache')]
final class CacheableTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        CacheRuntimeRegistry::reset();
    }

    #[Override]
    protected function tearDown(): void
    {
        CacheRuntimeRegistry::reset();
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

    public function testCachedPropagatesAutoDependencyExpiration(): void
    {
        $now = 4_000_000_000.0;
        CacheRuntimeRegistry::register(
            CacheRuntimeRegistry::DEFAULT_NAME,
            new CacheRuntime(new MemoryCache(), new MutableClock($now)),
        );
        $dependency = Cached::of('dependency', new CacheMetadata(expiresAt: $now + 15.0));

        $result = (new CachedQuery())->auto($dependency);

        self::assertSame('auto', $result->value());
        self::assertSame($now + 15.0, $result->metadata->expiresAt);
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
}
