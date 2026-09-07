<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\ComposedCacheStrategy;
use Magix\Cache\Strategy\CompositeCacheStrategy;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\AnsweringStrategy;
use Tests\Fixture\ProductCacheStrategy;

#[CoversClass(CompositeCacheStrategy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesClass(CacheOperation::class)]
#[UsesClass(ComposedCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\KeySpreadExpirationStrategy::class)]
#[UsesClass(NextCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class)]
#[UsesClass(OriginResult::class)]
#[UsesClass(\Magix\Cache\Strategy\StrategyArguments::class)]
#[UsesClass(\Magix\Cache\Strategy\StrategyDefinition::class)]
final class CompositeCacheStrategyTest extends TestCase
{
    public function testCreateComposesIntoOneRunnableStrategy(): void
    {
        $strategy = ProductCacheStrategy::create(min: 60)->instantiate();
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $result = $strategy->fetch($operation, NextCacheStrategy::of($terminal));
        self::assertInstanceOf(OriginResult::class, $result);

        self::assertSame('origin', $result->cached->value());
        self::assertSame(160.0, $result->cached->metadata->expiresAt, 'min: 60 leaves the spread no width');
    }

    public function testCreateResultComposesAgain(): void
    {
        $composed = new ComposedCacheStrategy(ProductCacheStrategy::create(min: 60)->instantiate(), ProductCacheStrategy::create(min: 30)->instantiate());
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $fetched1 = $composed->fetch($operation, NextCacheStrategy::of($terminal));
        self::assertInstanceOf(OriginResult::class, $fetched1);
        $expiresAt = $fetched1->cached->metadata->expiresAt;

        self::assertNotNull($expiresAt);
        self::assertLessThanOrEqual(160.0, $expiresAt, 'the meet keeps the earliest composed constraint');
        self::assertGreaterThanOrEqual(130.0, $expiresAt);
    }
}
