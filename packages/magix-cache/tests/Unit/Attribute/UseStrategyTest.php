<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cached;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\CacheHandlers;
use Tests\Fixture\ProductCacheStrategy;

#[CoversClass(UseStrategy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesClass(\Magix\Cache\Strategy\ComposedCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\CompositeCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\KeySpreadExpirationStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\StrategyArguments::class)]
#[UsesClass(\Magix\Cache\Strategy\StrategyDefinition::class)]
#[UsesNamespace('Magix\Cache')]
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
        $strategy = (new UseStrategy(strategy: ProductCacheStrategy::class, min: 60))->resolve()->instantiate((new \Magix\Cache\Runtime\StrategyFactory(new \Tests\Fixture\MutableClock(100.0), null))->create(...));
        $handlers = new CacheHandlers(hit: null, fetched: Cached::of('origin'));
        $key = 'key';

        $result = $strategy->fetch($key, $handlers->fetch(...));


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
