<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Strategy\ComposedCacheStrategy;
use Magix\Cache\Strategy\CompositeCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\CacheHandlers;
use Tests\Fixture\ProductCacheStrategy;

#[CoversClass(CompositeCacheStrategy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesClass(ComposedCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\KeySpreadExpirationStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\StrategyArguments::class)]
#[UsesClass(\Magix\Cache\Strategy\StrategyDefinition::class)]
#[UsesNamespace('Magix\Cache')]
final class CompositeCacheStrategyTest extends TestCase
{
    public function testCreateComposesIntoOneRunnableStrategy(): void
    {
        $strategy = ProductCacheStrategy::create(min: 60)->instantiate((new \Magix\Cache\Runtime\StrategyFactory(new \Tests\Fixture\MutableClock(100.0), null))->create(...));
        $handlers = new CacheHandlers(hit: null, fetched: Cached::of('origin'));
        $key = 'key';

        $result = $strategy->fetch($key, $handlers->fetch(...))->wait();


        self::assertSame('origin', $result->value());
        self::assertSame(160.0, $result->metadata->expiresAt, 'min: 60 leaves the spread no width');
    }

    public function testCreateResultComposesAgain(): void
    {
        $composed = new ComposedCacheStrategy(ProductCacheStrategy::create(min: 60)->instantiate((new \Magix\Cache\Runtime\StrategyFactory(new \Tests\Fixture\MutableClock(100.0), null))->create(...)), ProductCacheStrategy::create(min: 30)->instantiate((new \Magix\Cache\Runtime\StrategyFactory(new \Tests\Fixture\MutableClock(100.0), null))->create(...)));
        $handlers = new CacheHandlers(hit: null, fetched: Cached::of('origin'));
        $key = 'key';

        $fetched1 = $composed->fetch($key, $handlers->fetch(...))->wait();

        $expiresAt = $fetched1->metadata->expiresAt;

        self::assertNotNull($expiresAt);
        self::assertLessThanOrEqual(160.0, $expiresAt, 'the meet keeps the earliest composed constraint');
        self::assertGreaterThanOrEqual(130.0, $expiresAt);
    }
}
