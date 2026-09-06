<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\NextCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\AnsweringStrategy;
use Tests\Fixture\ProductCacheStrategy;

#[CoversClass(UseStrategy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesClass(CacheOperation::class)]
#[UsesClass(\Magix\Cache\Strategy\ComposedCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\CompositeCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\KeySpreadExpirationStrategy::class)]
#[UsesClass(NextCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class)]
final class UseStrategyTest extends TestCase
{
    public function testCarriesTheTypedArgumentsForCreate(): void
    {
        $declaration = new UseStrategy(strategy: ProductCacheStrategy::class, min: 60);

        self::assertSame(ProductCacheStrategy::class, $declaration->strategy);
        self::assertSame(['min' => 60], $declaration->arguments);
        self::assertTrue($declaration->enabled);
    }

    public function testResolveBuildsTheStrategyThroughCreate(): void
    {
        $strategy = new UseStrategy(strategy: ProductCacheStrategy::class, min: 60)->resolve();
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $result = $strategy->fetch($operation, NextCacheStrategy::of($terminal));

        self::assertSame('origin', $result->value());
        self::assertSame(160.0, $result->metadata->expiresAt, 'the declared min: 60 pins the spread');
    }

    public function testDisablingKeepsTheDeclarationReadable(): void
    {
        $declaration = new UseStrategy(strategy: ProductCacheStrategy::class, enabled: false);

        self::assertFalse($declaration->enabled);
        self::assertSame([], $declaration->arguments);
    }
}
