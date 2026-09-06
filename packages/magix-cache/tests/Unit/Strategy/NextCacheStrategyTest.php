<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use ArrayObject;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\NextCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\AnsweringStrategy;
use Tests\Fixture\RecordingStrategy;

#[CoversClass(NextCacheStrategy::class)]
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

        self::assertSame('origin', $chain->fetch($operation)->value());
        self::assertSame(
            ['first.fetch.before', 'second.fetch.before', 'second.fetch.after', 'first.fetch.after'],
            $log->getArrayCopy(),
        );
    }

    public function testGetReachesTheAnswerAtTheEndOfTheChain(): void
    {
        $terminal = new AnsweringStrategy(hit: Cached::of('hit'), fetched: Cached::of('origin'));
        $chain = NextCacheStrategy::of(new RecordingStrategy('outer'), $terminal);
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertSame('hit', $chain->get($operation)?->value());
    }

    public function testSetReachesTheAnswerAtTheEndOfTheChain(): void
    {
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));
        $chain = NextCacheStrategy::of(new RecordingStrategy('outer'), $terminal);
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $result = Cached::of('value');

        $chain->set($operation, $result);

        self::assertSame($result, $terminal->stored);
    }

    public function testPrependBindsInFrontOfTheExistingChain(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $terminal = new AnsweringStrategy(hit: Cached::of('hit'), fetched: Cached::of('origin'));
        $chain = NextCacheStrategy::of($terminal)->prepend(new RecordingStrategy('outer', $log));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertSame('hit', $chain->get($operation)?->value());
        self::assertSame(['outer.get.before', 'outer.get.after'], $log->getArrayCopy());
    }

    public function testEndCarriesNoStrategyAndBindsThroughPrepend(): void
    {
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('origin'));
        $chain = NextCacheStrategy::end()->prepend($terminal);
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertSame('origin', $chain->fetch($operation)->value());
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

        self::assertSame($terminal, new NextCacheStrategy($terminal)->strategy());
    }
}
