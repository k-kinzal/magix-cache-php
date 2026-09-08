<?php

declare(strict_types=1);

namespace Bench;

use Bench\AllMiss\Catalog;
use Bench\AllMiss\FixedClock;
use Bench\AllMiss\MagixQuery;
use Bench\AllMiss\MemoryCache;
use Bench\AllMiss\PlainQuery;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use PhpBench\Attributes as Bench;

/**
 * Measures one complete read-model tree, including every missed read and write.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\AfterMethods('tearDown')]
final class AllMissBench
{
    private PlainQuery $plain;

    private MagixQuery $magix;

    private int $id = 0;

    /**
     * Retains the final value so both subjects have an observable result.
     */
    public int $result = 0;

    /**
     * Builds an independent environment before each sample and its warm-up.
     */
    public function setUp(): void
    {
        CacheRuntimeRegistry::reset();
        CacheRuntimeRegistry::register('default', new CacheRuntime(new MemoryCache(), new FixedClock()));
        $catalog = new Catalog();
        $this->plain = new PlainQuery($catalog);
        $this->magix = new MagixQuery($catalog);
        $this->id = 0;
    }

    /**
     * Releases the runtime and its retained entries after each sample.
     */
    public function tearDown(): void
    {
        CacheRuntimeRegistry::reset();
    }

    /**
     * Names the single and composed query trees.
     *
     * @return array<string, array{depth: int, width: int}>
     */
    public function provideTrees(): array
    {
        return [
            'single-1-boundary' => ['depth' => 0, 'width' => 2],
            'composed-3-boundaries' => ['depth' => 1, 'width' => 2],
            'composed-15-boundaries' => ['depth' => 3, 'width' => 2],
        ];
    }

    /**
     * Varies tree depth and fan-out around one hundred cache boundaries.
     *
     * @return array<string, array{depth: int, width: int}>
     */
    public function provideLargeTrees(): array
    {
        return [
            'deep-wide-127-boundaries' => ['depth' => 6, 'width' => 2],
            'deep-wide-121-boundaries' => ['depth' => 4, 'width' => 3],
            'wide-111-boundaries' => ['depth' => 2, 'width' => 10],
        ];
    }

    /**
     * Measures one complete query tree with a never-repeated root ID.
     *
     * @param array{depth: int, width: int} $params
     */
    #[Bench\ParamProviders('provideTrees')]
    #[Bench\Revs(100000)]
    public function benchWithoutMagixCache(array $params): void
    {
        $this->result = $this->plain->execute(++$this->id, $params['depth'], $params['width']);
    }

    /**
     * Measures one complete query tree with a never-repeated root ID.
     *
     * @param array{depth: int, width: int} $params
     */
    #[Bench\ParamProviders('provideTrees')]
    #[Bench\Revs(5000)]
    public function benchMagixCacheAllMiss(array $params): void
    {
        $this->result = $this->magix->execute(++$this->id, $params['depth'], $params['width'])->value();
    }

    /**
     * Measures a complete large tree with enough revolutions for stable timing.
     *
     * @param array{depth: int, width: int} $params
     */
    #[Bench\ParamProviders('provideLargeTrees')]
    #[Bench\Revs(10000)]
    public function benchWithoutMagixCacheLarge(array $params): void
    {
        $this->result = $this->plain->execute(++$this->id, $params['depth'], $params['width']);
    }

    /**
     * Measures a complete large tree while bounding retained sample data.
     *
     * @param array{depth: int, width: int} $params
     */
    #[Bench\ParamProviders('provideLargeTrees')]
    #[Bench\Revs(500)]
    public function benchMagixCacheAllMissLarge(array $params): void
    {
        $this->result = $this->magix->execute(++$this->id, $params['depth'], $params['width'])->value();
    }
}
