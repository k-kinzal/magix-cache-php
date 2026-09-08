<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bench\AllMiss\Catalog;
use Bench\AllMiss\FixedClock;
use Bench\AllMiss\MagixQuery;
use Bench\AllMiss\MemoryCache;
use Bench\AllMiss\PlainQuery;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Magix\Cache\Runtime\Extension\CacheEvent;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\RecordingObserver;

#[CoversNothing]
final class AllMissBenchmarkTest extends TestCase
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

    #[DataProvider('providerTrees')]
    public function testNewInputsMissAndStoreEveryBoundaryWithTheSameResult(int $depth, int $boundaries): void
    {
        $cache = new MemoryCache();
        $observer = new RecordingObserver();
        CacheRuntimeRegistry::register('default', new CacheRuntime($cache, new FixedClock(), observer: $observer));
        $catalog = new Catalog();
        $plain = new PlainQuery($catalog);
        $magix = new MagixQuery($catalog);

        self::assertSame($plain->execute(1, $depth), $magix->execute(1, $depth)->value());
        self::assertSame($plain->execute(2, $depth), $magix->execute(2, $depth)->value());
        self::assertSame($plain->execute(3, $depth), $magix->execute(3, $depth)->value());
        self::assertCount(3 * $boundaries, array_filter($observer->events, static fn (CacheEvent $event): bool => $event === CacheEvent::Miss));
        self::assertCount(3 * $boundaries, array_filter($observer->events, static fn (CacheEvent $event): bool => $event === CacheEvent::Stored));
        self::assertCount(6 * $boundaries, $observer->events, 'Every boundary only misses and stores, including subsequent revolutions.');
        self::assertSame(3 * $boundaries, $cache->count());

        self::assertSame($plain->execute(2, $depth), $magix->execute(2, $depth)->value());
        self::assertSame(CacheEvent::FreshHit, array_slice($observer->events, -1)[0] ?? null);
        self::assertCount(6 * $boundaries + 1, $observer->events, 'A repeated root key really hits the in-memory cache.');
        self::assertSame(3 * $boundaries, $cache->count());
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function providerTrees(): array
    {
        return [
            'single' => [0, 1],
            'two levels' => [1, 3],
            'four levels' => [3, 15],
        ];
    }
}
