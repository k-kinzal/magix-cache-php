<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use ArrayObject;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\AnsweringStrategy;
use Tests\Fixture\RecordingStrategy;

#[CoversClass(NextCacheStrategy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(CacheOperation::class)]
#[UsesClass(\Magix\Cache\Strategy\CacheRead::class)]
#[UsesClass(CacheWrite::class)]
#[UsesClass(OriginResult::class)]
final class NextCacheStrategyTest extends TestCase
{
    public function testFetchRunsStrategiesInOrderDownToTheAnswer(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $first = new RecordingStrategy('first', $log);
        $second = new RecordingStrategy('second', $log);
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));
        $chain = NextCacheStrategy::of($first, $second, $terminal);
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $fetched1 = $chain->fetch($operation);
        self::assertInstanceOf(OriginResult::class, $fetched1);
        self::assertSame('origin', $fetched1->cached->value());
        self::assertSame(
            ['first.fetch.before', 'second.fetch.before', 'second.fetch.after', 'first.fetch.after'],
            $log->getArrayCopy(),
        );
    }

    public function testGetReachesTheAnswerAtTheEndOfTheChain(): void
    {
        $terminal = new AnsweringStrategy(hit: Cached::of('hit', new \Magix\Cache\Metadata\CacheMetadata(expiresAt: 150.0)), fetched: Cached::of('origin'));
        $chain = NextCacheStrategy::of(new RecordingStrategy('outer'), $terminal);
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertSame('hit', $chain->get($operation)?->cached->value());
    }

    public function testSetReachesTheAnswerAtTheEndOfTheChain(): void
    {
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));
        $chain = NextCacheStrategy::of(new RecordingStrategy('outer'), $terminal);
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $result = Cached::of('value');

        $chain->set($operation, new CacheWrite($result));

        self::assertSame($result, $terminal->stored?->cached);
    }

    public function testPrependBindsInFrontOfTheExistingChain(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $terminal = new AnsweringStrategy(hit: Cached::of('hit', new \Magix\Cache\Metadata\CacheMetadata(expiresAt: 150.0)), fetched: Cached::of('origin'));
        $chain = NextCacheStrategy::of($terminal)->prepend(new RecordingStrategy('outer', $log));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertSame('hit', $chain->get($operation)?->cached->value());
        self::assertSame(['outer.get.before', 'outer.get.after'], $log->getArrayCopy());
    }

    public function testEndCarriesNoStrategyAndBindsThroughPrepend(): void
    {
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));
        $chain = NextCacheStrategy::end()->prepend($terminal);
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $fetched2 = $chain->fetch($operation);
        self::assertInstanceOf(OriginResult::class, $fetched2);
        self::assertSame('origin', $fetched2->cached->value());
    }

    public function testOfBindsTheGivenStrategiesInOrder(): void
    {
        $first = new RecordingStrategy('first');
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));

        self::assertSame($first, NextCacheStrategy::of($first, $terminal)->strategy());
    }

    public function testStrategyReturnsTheStrategyOfTheFrontLink(): void
    {
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));

        self::assertSame($terminal, (new NextCacheStrategy($terminal))->strategy());
    }
}
