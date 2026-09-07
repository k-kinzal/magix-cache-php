<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use ArrayObject;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\ComposedCacheStrategy;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\AnsweringStrategy;
use Tests\Fixture\RecordingStrategy;

#[CoversClass(ComposedCacheStrategy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(CacheOperation::class)]
#[UsesClass(NextCacheStrategy::class)]
#[UsesClass(OriginResult::class)]
#[UsesClass(CacheWrite::class)]
#[UsesClass(\Magix\Cache\Strategy\CacheRead::class)]
final class ComposedCacheStrategyTest extends TestCase
{
    public function testFetchActsAsOneStrategyInCompositionOrder(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $composed = new ComposedCacheStrategy(
            new RecordingStrategy('outer', $log),
            new RecordingStrategy('inner', $log),
        );
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $result = $composed->fetch($operation, NextCacheStrategy::of($terminal));
        self::assertInstanceOf(OriginResult::class, $result);

        self::assertSame('origin', $result->cached->value());
        self::assertSame(
            ['outer.fetch.before', 'inner.fetch.before', 'inner.fetch.after', 'outer.fetch.after'],
            $log->getArrayCopy(),
        );
    }

    public function testFetchComposesAgainWithoutLosingOrder(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $composed = new ComposedCacheStrategy(
            new ComposedCacheStrategy(
                new RecordingStrategy('a', $log),
                new RecordingStrategy('b', $log),
            ),
            new RecordingStrategy('c', $log),
        );
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $fetched1 = $composed->fetch($operation, NextCacheStrategy::of($terminal));
        self::assertInstanceOf(OriginResult::class, $fetched1);
        self::assertSame('origin', $fetched1->cached->value());
        self::assertSame(
            ['a.fetch.before', 'b.fetch.before', 'c.fetch.before', 'c.fetch.after', 'b.fetch.after', 'a.fetch.after'],
            $log->getArrayCopy(),
        );
    }

    public function testSetDelegatesThroughTheSequence(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $composed = new ComposedCacheStrategy(
            new RecordingStrategy('outer', $log),
            new RecordingStrategy('inner', $log),
        );
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $result = Cached::of('value');

        $composed->set($operation, new CacheWrite($result), NextCacheStrategy::of($terminal));

        self::assertSame($result, $terminal->stored?->cached);
        self::assertSame(
            ['outer.set.before', 'inner.set.before', 'inner.set.after', 'outer.set.after'],
            $log->getArrayCopy(),
        );
    }

    public function testGetDelegatesThroughTheComposition(): void
    {
        $composed = new ComposedCacheStrategy(new RecordingStrategy('only'));
        $terminal = new AnsweringStrategy(hit: Cached::of('hit', new \Magix\Cache\Metadata\CacheMetadata(expiresAt: 150.0)), fetched: Cached::of('origin'));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertSame('hit', $composed->get($operation, NextCacheStrategy::of($terminal))?->cached->value());
    }
}
