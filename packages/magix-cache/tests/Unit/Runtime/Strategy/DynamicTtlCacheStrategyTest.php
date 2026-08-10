<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Strategy;

use InvalidArgumentException;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchResult;
use Magix\Cache\Runtime\Strategy\CacheStrategyMiddleware;
use Magix\Cache\Runtime\Strategy\DynamicTtlCacheStrategy;
use Magix\Cache\Runtime\Strategy\DynamicTtlContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MutableClock;

#[CoversClass(DynamicTtlCacheStrategy::class)]
#[UsesClass(CacheStrategyMiddleware::class)]
#[UsesClass(DynamicTtlContext::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(OriginFetch::class)]
#[UsesClass(OriginFetchResult::class)]
#[UsesClass(\Magix\Cache\Runtime\Operation\OriginFetchOutcome::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class DynamicTtlCacheStrategyTest extends TestCase
{
    public function testFetchAddsDynamicExpirationToOriginResult(): void
    {
        $strategy = new DynamicTtlCacheStrategy(static fn (DynamicTtlContext $context): int => match (
            $context->result->value()
        ) {
            'short' => 5,
            default => 30,
        });
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('short'),
            null,
            new MutableClock(100.0),
        );
        $origin = new OriginFetchResult(Cached::of('short'));

        $result = $strategy->fetch($operation, static fn (): OriginFetchResult => $origin);

        self::assertSame(105.0, $result->originValue()->metadata->expiresAt);
    }

    public function testFetchOnlyShortensExistingExpiration(): void
    {
        $strategy = new DynamicTtlCacheStrategy(static fn (DynamicTtlContext $context): int => 30);
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('value'),
            null,
            new MutableClock(100.0),
        );
        $origin = new OriginFetchResult(Cached::of('value', new CacheMetadata(expiresAt: 110.0)));

        $result = $strategy->fetch($operation, static fn (): OriginFetchResult => $origin);

        self::assertSame(110.0, $result->originValue()->metadata->expiresAt);
    }

    public function testFetchLeavesStaleFallbackUnchanged(): void
    {
        $strategy = new DynamicTtlCacheStrategy(static fn (DynamicTtlContext $context): int => 30);
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('value'),
            null,
            new MutableClock(100.0),
        );
        $stale = new OriginFetchResult(new CacheEntry('stale', 90.0, retainedUntil: 120.0));

        self::assertSame($stale, $strategy->fetch($operation, static fn (): OriginFetchResult => $stale));
    }

    public function testFetchRejectsNegativeDynamicTtl(): void
    {
        $strategy = new DynamicTtlCacheStrategy(static fn (DynamicTtlContext $context): int => -1);
        $operation = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('value'),
            null,
            new MutableClock(100.0),
        );

        $this->expectException(InvalidArgumentException::class);

        $strategy->fetch(
            $operation,
            static fn (): OriginFetchResult => new OriginFetchResult(Cached::of('value')),
        );
    }
}
