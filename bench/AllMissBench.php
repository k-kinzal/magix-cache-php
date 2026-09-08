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
#[Bench\ParamProviders('provideTrees')]
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
     * @return array<string, array{depth: int}>
     */
    public function provideTrees(): array
    {
        return [
            'single-1-boundary' => ['depth' => 0],
            'composed-3-boundaries' => ['depth' => 1],
            'composed-15-boundaries' => ['depth' => 3],
        ];
    }

    /**
     * Measures one complete query tree with a never-repeated root ID.
     *
     * @param array{depth: int} $params
     */
    #[Bench\Revs(100000)]
    public function benchWithoutMagixCache(array $params): void
    {
        $this->result = $this->plain->execute(++$this->id, $params['depth']);
    }

    /**
     * Measures one complete query tree with a never-repeated root ID.
     *
     * @param array{depth: int} $params
     */
    #[Bench\Revs(5000)]
    public function benchMagixCacheAllMiss(array $params): void
    {
        $this->result = $this->magix->execute(++$this->id, $params['depth'])->value();
    }
}
