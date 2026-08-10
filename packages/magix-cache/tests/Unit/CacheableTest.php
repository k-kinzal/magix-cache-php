<?php

declare(strict_types=1);

namespace Tests\Unit;

use Magix\Cache\Cached;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\CacheKeyStrategy;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use Override;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\CachedQuery;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\MutableClock;

#[CoversTrait(\Magix\Cache\Cacheable::class)]
#[UsesNamespace('Magix\Cache')]
final class CacheableTest extends TestCase
{
    public function testCachedUsesRuntimeCacheKeyStrategy(): void
    {
        $strategy = new class () implements CacheKeyStrategy {
            #[Override]
            public function generate(CacheKeyContext $context): string
            {
                unset($context);

                return 'shared-custom-key';
            }
        };
        $runtime = new CacheRuntime(
            new MemoryCache(),
            new MutableClock(4_000_000_000.0),
            $strategy,
        );
        CacheRuntime::setCurrent($runtime);
        $query = new CachedQuery();

        $first = $query->execute(1);
        $second = $query->execute(2);
        CacheRuntime::setCurrent(null);

        self::assertSame($strategy, $runtime->keyStrategy());
        self::assertSame('1:', $first->value());
        self::assertEquals($first, $second);
        self::assertSame(1, $query->calls);
    }

    public function testCachedResolvesAttributesAndArgumentsBeforeRuntime(): void
    {
        CacheRuntime::setCurrent(new CacheRuntime(new MemoryCache(), new MutableClock(4_000_000_000.0)));
        $query = new CachedQuery();

        $first = $query->execute(1, 'trace-a');
        $second = $query->execute(1, 'trace-b');
        CacheRuntime::setCurrent(null);

        self::assertSame('1:trace-a', $first->value());
        self::assertEquals($first, $second);
        self::assertSame(1, $query->calls);
    }

    public function testCachedPropagatesAutoDependencyExpiration(): void
    {
        $now = 4_000_000_000.0;
        CacheRuntime::setCurrent(new CacheRuntime(new MemoryCache(), new MutableClock($now)));
        $dependency = Cached::of('dependency', new CacheMetadata(expiresAt: $now + 15.0));
        $result = (new CachedQuery())->auto($dependency);
        CacheRuntime::setCurrent(null);

        self::assertSame('auto', $result->value());
        self::assertSame($now + 15.0, $result->metadata->expiresAt);
    }

    public function testCachedAcceptsPolicyAsSecondArgument(): void
    {
        $now = 4_000_000_000.0;
        CacheRuntime::setCurrent(new CacheRuntime(new MemoryCache(), new MutableClock($now)));
        $result = (new CachedQuery())->explicit(1);
        CacheRuntime::setCurrent(null);

        self::assertSame($now + 15.0, $result->metadata->expiresAt);
    }

    public function testCachedAcceptsPerBoundaryStrategyAsThirdArgument(): void
    {
        $now = 4_000_000_000.0;
        CacheRuntime::setCurrent(new CacheRuntime(new MemoryCache(), new MutableClock($now)));
        $query = new CachedQuery();

        $first = $query->dynamic(1);
        $second = $query->dynamic(1);
        CacheRuntime::setCurrent(null);

        self::assertSame($now + 7.0, $first->metadata->expiresAt);
        self::assertEquals($first, $second);
        self::assertSame(1, $query->calls);
    }

    public function testCachedAllowsIgnoredUnkeyableArgumentForNoStorePolicy(): void
    {
        CacheRuntime::setCurrent(new CacheRuntime(new MemoryCache(), new MutableClock(100.0)));
        $query = new CachedQuery();

        $first = $query->noStore(static fn (): string => 'first');
        $second = $query->noStore(static fn (): string => 'second');
        CacheRuntime::setCurrent(null);

        self::assertSame('first', $first->value());
        self::assertSame('second', $second->value());
        self::assertSame(2, $query->calls);
    }
}
