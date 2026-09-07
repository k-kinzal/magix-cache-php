<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheAnswer;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\AnsweringStrategy;

#[CoversClass(KeySpreadExpirationStrategy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesClass(CacheOperation::class)]
#[UsesClass(NextCacheStrategy::class)]
#[UsesClass(OriginResult::class)]
#[UsesClass(\Magix\Cache\Strategy\CacheRead::class)]
#[UsesClass(CacheAnswer::class)]
#[UsesClass(CacheWrite::class)]
final class KeySpreadExpirationStrategyTest extends TestCase
{
    public function testFetchMeetsADeterministicConstraintWithinTheRange(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 30, maximum: 60);
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('value'));
        $first = new CacheOperation('key', static fn (): float => 100.0);
        $second = new CacheOperation('key', static fn (): float => 100.0);

        $fetched1 = $strategy->fetch($first, NextCacheStrategy::of($terminal));
        self::assertInstanceOf(OriginResult::class, $fetched1);
        $expiresAt = $fetched1->cached->metadata->expiresAt;

        self::assertNotNull($expiresAt);
        self::assertGreaterThanOrEqual(130.0, $expiresAt);
        self::assertLessThanOrEqual(160.0, $expiresAt);
        $fetched2 = $strategy->fetch($second, NextCacheStrategy::of($terminal));
        self::assertInstanceOf(OriginResult::class, $fetched2);
        self::assertSame($expiresAt, $fetched2->cached->metadata->expiresAt);
    }

    public function testFetchNeverExtendsAnExistingExpiration(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 30, maximum: 60);
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('value', new CacheMetadata(expiresAt: 110.0)));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $fetched3 = $strategy->fetch($operation, NextCacheStrategy::of($terminal));
        self::assertInstanceOf(OriginResult::class, $fetched3);
        self::assertSame(110.0, $fetched3->cached->metadata->expiresAt);
    }

    public function testFetchPinsTheConstraintWhenTheBoundsAreEqual(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 60, maximum: 60);
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('value'));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $fetched4 = $strategy->fetch($operation, NextCacheStrategy::of($terminal));
        self::assertInstanceOf(OriginResult::class, $fetched4);
        self::assertSame(160.0, $fetched4->cached->metadata->expiresAt);
    }

    public function testFetchLeavesAServedStaleCandidateUntouched(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 30, maximum: 60);
        $stale = Cached::of('stale', new CacheMetadata(expiresAt: 90.0));
        $terminal = new AnsweringStrategy(hit: null, fetched: $stale, succeeds: false);
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $fetched5 = $strategy->fetch($operation, NextCacheStrategy::of($terminal));
        self::assertInstanceOf(CacheAnswer::class, $fetched5);
        self::assertSame(90.0, $fetched5->cached->metadata->expiresAt);
    }

    public function testGetDelegatesTheLookupUnchanged(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 30, maximum: 60);
        $terminal = new AnsweringStrategy(hit: Cached::of('hit', new CacheMetadata(expiresAt: 150.0)), fetched: Cached::of('value'));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertSame('hit', $strategy->get($operation, NextCacheStrategy::of($terminal))?->cached->value());
    }

    public function testSetDelegatesTheStoreUnchanged(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 30, maximum: 60);
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('value'));
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $result = Cached::of('value', new CacheMetadata(expiresAt: 150.0));

        $strategy->set($operation, new CacheWrite($result), NextCacheStrategy::of($terminal));

        self::assertSame($result, $terminal->stored?->cached);
        self::assertNull($terminal->stored->retainedUntil);
    }
}
