<?php

declare(strict_types=1);

namespace Tests\Unit;

use Generator;
use GuzzleHttp\Promise\Utils;
use Magix\Cache\AsyncCached;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\PendingResult;
use Throwable;

#[CoversClass(AsyncCached::class)]
#[UsesNamespace('Magix\Cache')]
final class AsyncCachedTest extends TestCase
{
    public function testMapRunsAfterCompletionOnceAndWaitsOnlyAtExtraction(): void
    {
        $source = new PendingResult(4);
        $metadata = new CacheMetadata(expiresAt: 123.25, tags: ['source'], reasons: ['reason']);
        $calls = 0;
        $result = AsyncCached::fromPromise($source->promise(), $metadata)->map(static function (int $value) use (&$calls): int {
            ++$calls;

            return $value * 2;
        });
        self::assertSame(0, $calls);
        self::assertSame(0, $source->waits);
        self::assertSame(8, $result->value());
        self::assertSame(8, $result->value());
        self::assertSame($metadata, $result->toCached()->metadata);
        self::assertSame(1, $calls);
        self::assertSame(1, $source->waits);
    }

    public function testZipCanCompleteOutOfOrderWithoutWaitingForEitherInput(): void
    {
        $left = new PendingResult('left');
        $right = new PendingResult('right');
        $a = new CacheMetadata(expiresAt: 150.25, tags: ['a']);
        $b = new CacheMetadata(expiresAt: 140.5, visibility: Visibility::Private, tags: ['b']);
        $result = AsyncCached::fromPromise($left->promise(), $a)->zip(AsyncCached::fromPromise($right->promise(), $b));
        $right->complete();
        Utils::queue()->run();
        self::assertSame(0, $left->waits);
        self::assertSame(0, $right->waits);
        $left->complete();
        Utils::queue()->run();
        self::assertSame(['left', 'right'], $result->value());
        self::assertEquals($a->meet($b), $result->toCached()->metadata);
        self::assertSame(0, $left->waits);
        self::assertSame(0, $right->waits);
    }

    public function testSequencePreservesKeyOrderAndMeetsOverwrittenItems(): void
    {
        $a = new CacheMetadata(expiresAt: 20.5, visibility: Visibility::Private, tags: ['overwritten']);
        $source = new PendingResult('old');
        $items = static function () use ($source, $a): Generator {
            yield 'z' => AsyncCached::fromPromise($source->promise(), $a);
            yield 9 => AsyncCached::of('middle');
            yield 'z' => AsyncCached::of('new', new CacheMetadata(expiresAt: 60.0, tags: ['last']));
        };
        $result = AsyncCached::sequence($items());
        self::assertSame(0, $source->waits);
        self::assertSame(['z' => 'new', 9 => 'middle'], $result->value());
        self::assertSame(20.5, $result->toCached()->metadata->expiresAt);
        self::assertSame(['last', 'overwritten'], $result->toCached()->metadata->tags);
        self::assertSame(Visibility::Private, $result->toCached()->metadata->visibility);
        self::assertEquals(Cached::of([]), AsyncCached::sequence([])->toCached());
    }

    public function testTraverseStartsAllComputationsBeforeWaiting(): void
    {
        $sources = [new PendingResult(2), new PendingResult(3)];
        $calls = 0;
        $result = AsyncCached::traverse($sources, static function (PendingResult $source) use (&$calls): AsyncCached {
            ++$calls;

            return AsyncCached::fromPromise($source->promise());
        });
        self::assertSame(2, $calls);
        self::assertSame(0, $sources[0]->waits);
        self::assertSame(0, $sources[1]->waits);
        self::assertSame([2, 3], $result->value());
        self::assertSame(1, $sources[0]->waits);
        self::assertSame(1, $sources[1]->waits);
    }

    public function testToCachedPreservesNestedCachedUntilExplicitFlatten(): void
    {
        $inner = Cached::of('value', new CacheMetadata(expiresAt: 20.0, tags: ['inner']));
        $outer = new CacheMetadata(expiresAt: 60.0, tags: ['outer']);
        $result = AsyncCached::of($inner, $outer)->toCached();
        self::assertSame($inner, $result->value());
        self::assertSame($outer, $result->metadata);
        self::assertEquals(Cached::of('value', $outer->meet($inner->metadata)), $result->flatten());
        self::assertSame($inner, AsyncCached::of(1)->map(static fn (): Cached => $inner)->value());
    }

    public function testFlattenRemovesOneAsyncLayerAndMeetsItsMetadata(): void
    {
        $inner = AsyncCached::of('value', new CacheMetadata(expiresAt: 20.0, tags: ['inner']));
        $middle = AsyncCached::of($inner, new CacheMetadata(tags: ['middle']));
        $result = AsyncCached::of($middle, new CacheMetadata(tags: ['outer']))->flatten();
        self::assertSame($inner, $result->value());
        self::assertSame(['middle', 'outer'], $result->toCached()->metadata->tags);
        self::assertSame('value', $result->flatten()->value());
        self::assertSame(['inner', 'middle', 'outer'], $result->flatten()->toCached()->metadata->tags);
    }

    public function testUnzipPreservesTheWholePairsMetadataWithoutWaiting(): void
    {
        $source = new PendingResult(['left', 2]);
        $metadata = new CacheMetadata(expiresAt: 30.25, visibility: Visibility::Private, tags: ['pair']);
        [$left, $right] = AsyncCached::fromPromise($source->promise(), $metadata)->unzip();
        self::assertSame(0, $source->waits);
        self::assertSame('left', $left->value());
        self::assertSame(2, $right->value());
        self::assertSame($metadata, $left->toCached()->metadata);
        self::assertSame($metadata, $right->toCached()->metadata);
        self::assertSame(1, $source->waits);
    }

    public function testFlatMapSatisfiesMonadAndFunctorLawsWithMetadata(): void
    {
        $m = AsyncCached::of(2, new CacheMetadata(expiresAt: 40.0, tags: ['m']));
        $f = static fn (int $value): AsyncCached => AsyncCached::of($value + 3, new CacheMetadata(expiresAt: 30.0, tags: ['f']));
        $g = static fn (int $value): AsyncCached => AsyncCached::of($value * 2, new CacheMetadata(visibility: Visibility::Private, tags: ['g']));
        self::assertEquals($m->toCached(), $m->map(static fn (int $x): int => $x)->toCached());
        self::assertEquals($m->map(static fn (int $x): int => $x + 3)->map(static fn (int $x): int => $x * 2)->toCached(), $m->map(static fn (int $x): int => ($x + 3) * 2)->toCached());
        self::assertEquals($f(2)->toCached(), AsyncCached::of(2)->flatMap($f)->toCached());
        self::assertEquals($m->toCached(), $m->flatMap(static fn (int $x): AsyncCached => AsyncCached::of($x))->toCached());
        self::assertEquals($m->flatMap($f)->flatMap($g)->toCached(), $m->flatMap(static fn (int $x): AsyncCached => $f($x)->flatMap($g))->toCached());
    }

    public function testCombine10KeepsAllConstraintsAndDoesNotWaitAtConstruction(): void
    {
        $source = new PendingResult(1);
        $first = AsyncCached::fromPromise($source->promise(), new CacheMetadata(expiresAt: 50.0, tags: ['first']));
        $last = AsyncCached::of(10, new CacheMetadata(expiresAt: 20.0, tags: ['last']));
        $result = $first->combine10(AsyncCached::of(2), AsyncCached::of(3), AsyncCached::of(4), AsyncCached::of(5), AsyncCached::of(6), AsyncCached::of(7), AsyncCached::of(8), AsyncCached::of(9), $last)
            ->map(static fn (int $a, int $b, int $c, int $d, int $e, int $f, int $g, int $h, int $i, int $j): int => $a + $b + $c + $d + $e + $f + $g + $h + $i + $j);
        self::assertSame(0, $source->waits);
        self::assertSame(55, $result->value());
        self::assertSame(20.0, $result->toCached()->metadata->expiresAt);
        self::assertSame(['first', 'last'], $result->toCached()->metadata->tags);
    }
    public function testOfKeepsANestedCachedAsThePayload(): void
    {
        $inner = Cached::of('inner', new CacheMetadata(tags: ['inner']));
        $outer = AsyncCached::of($inner, new CacheMetadata(tags: ['outer']))->toCached();
        self::assertSame($inner, $outer->value());
        self::assertSame(['outer'], $outer->metadata->tags);
    }

    public function testFromCachedPromisePreservesTheCompleteResult(): void
    {
        $cached = Cached::of('value', new CacheMetadata(expiresAt: 42.25, tags: ['value']));
        self::assertSame($cached, AsyncCached::fromCachedPromise(\Magix\Cache\Async\Promise::resolved($cached))->toCached());
        self::assertSame($cached, AsyncCached::fromCached($cached)->toCached());
    }

    public function testFromPromiseKeepsAnEventualCachedNested(): void
    {
        $inner = Cached::of('value', new CacheMetadata(tags: ['inner']));
        $source = new PendingResult($inner);
        $result = AsyncCached::fromPromise($source->promise());
        self::assertSame(0, $source->waits);
        self::assertSame($inner, $result->value());
        self::assertSame([], $result->toCached()->metadata->tags);
    }

    public function testFromGuzzleImportsTheResultWithoutExposingAPromise(): void
    {
        $result = AsyncCached::fromGuzzle(new \GuzzleHttp\Promise\FulfilledPromise('value'), new CacheMetadata(tags: ['guzzle']));
        self::assertSame('value', $result->value());
        self::assertSame(['guzzle'], $result->toCached()->metadata->tags);
    }

    public function testSubscribeObservesCompletionWithoutWaiting(): void
    {
        $source = new PendingResult('value');
        $result = AsyncCached::fromPromise($source->promise());
        $received = null;
        $result->subscribe(static function (Cached $cached) use (&$received): void {
            $received = $cached->value();
        }, static function (Throwable $error): void {
            self::fail($error->getMessage());
        });
        self::assertNull($received);
        self::assertSame(0, $source->waits);
        $source->complete();
        Utils::queue()->run();
        self::assertSame('value', $received);
        self::assertSame(0, $source->waits);
    }

    public function testValueDrivesTheSourceOnlyOnce(): void
    {
        $source = new PendingResult('value');
        $result = AsyncCached::fromPromise($source->promise());
        self::assertSame('value', $result->value());
        self::assertSame('value', $result->value());
        self::assertSame(1, $source->waits);
    }

    public function testCombine2KeepsTheFirstInputsConstraints(): void
    {
        $metadata = new CacheMetadata(expiresAt: 23.5, tags: ['source']);
        $result = AsyncCached::of(1, $metadata)->combine2(AsyncCached::of(2))
            ->map(static fn (int $v1, int $v2): int => $v1 + $v2);
        self::assertSame(3, $result->value());
        self::assertEquals($metadata, $result->toCached()->metadata);
    }

    public function testCombine3KeepsTheFirstInputsConstraints(): void
    {
        $metadata = new CacheMetadata(expiresAt: 23.5, tags: ['source']);
        $result = AsyncCached::of(1, $metadata)->combine3(AsyncCached::of(2), AsyncCached::of(3))
            ->map(static fn (int $v1, int $v2, int $v3): int => $v1 + $v2 + $v3);
        self::assertSame(6, $result->value());
        self::assertEquals($metadata, $result->toCached()->metadata);
    }

    public function testCombine4KeepsTheFirstInputsConstraints(): void
    {
        $metadata = new CacheMetadata(expiresAt: 23.5, tags: ['source']);
        $result = AsyncCached::of(1, $metadata)->combine4(AsyncCached::of(2), AsyncCached::of(3), AsyncCached::of(4))
            ->map(static fn (int $v1, int $v2, int $v3, int $v4): int => $v1 + $v2 + $v3 + $v4);
        self::assertSame(10, $result->value());
        self::assertEquals($metadata, $result->toCached()->metadata);
    }

    public function testCombine5KeepsTheFirstInputsConstraints(): void
    {
        $metadata = new CacheMetadata(expiresAt: 23.5, tags: ['source']);
        $result = AsyncCached::of(1, $metadata)->combine5(AsyncCached::of(2), AsyncCached::of(3), AsyncCached::of(4), AsyncCached::of(5))
            ->map(static fn (int $v1, int $v2, int $v3, int $v4, int $v5): int => $v1 + $v2 + $v3 + $v4 + $v5);
        self::assertSame(15, $result->value());
        self::assertEquals($metadata, $result->toCached()->metadata);
    }

    public function testCombine6KeepsTheFirstInputsConstraints(): void
    {
        $metadata = new CacheMetadata(expiresAt: 23.5, tags: ['source']);
        $result = AsyncCached::of(1, $metadata)->combine6(AsyncCached::of(2), AsyncCached::of(3), AsyncCached::of(4), AsyncCached::of(5), AsyncCached::of(6))
            ->map(static fn (int $v1, int $v2, int $v3, int $v4, int $v5, int $v6): int => $v1 + $v2 + $v3 + $v4 + $v5 + $v6);
        self::assertSame(21, $result->value());
        self::assertEquals($metadata, $result->toCached()->metadata);
    }

    public function testCombine7KeepsTheFirstInputsConstraints(): void
    {
        $metadata = new CacheMetadata(expiresAt: 23.5, tags: ['source']);
        $result = AsyncCached::of(1, $metadata)->combine7(AsyncCached::of(2), AsyncCached::of(3), AsyncCached::of(4), AsyncCached::of(5), AsyncCached::of(6), AsyncCached::of(7))
            ->map(static fn (int $v1, int $v2, int $v3, int $v4, int $v5, int $v6, int $v7): int => $v1 + $v2 + $v3 + $v4 + $v5 + $v6 + $v7);
        self::assertSame(28, $result->value());
        self::assertEquals($metadata, $result->toCached()->metadata);
    }

    public function testCombine8KeepsTheFirstInputsConstraints(): void
    {
        $metadata = new CacheMetadata(expiresAt: 23.5, tags: ['source']);
        $result = AsyncCached::of(1, $metadata)->combine8(AsyncCached::of(2), AsyncCached::of(3), AsyncCached::of(4), AsyncCached::of(5), AsyncCached::of(6), AsyncCached::of(7), AsyncCached::of(8))
            ->map(static fn (int $v1, int $v2, int $v3, int $v4, int $v5, int $v6, int $v7, int $v8): int => $v1 + $v2 + $v3 + $v4 + $v5 + $v6 + $v7 + $v8);
        self::assertSame(36, $result->value());
        self::assertEquals($metadata, $result->toCached()->metadata);
    }

    public function testCombine9KeepsTheFirstInputsConstraints(): void
    {
        $metadata = new CacheMetadata(expiresAt: 23.5, tags: ['source']);
        $result = AsyncCached::of(1, $metadata)->combine9(AsyncCached::of(2), AsyncCached::of(3), AsyncCached::of(4), AsyncCached::of(5), AsyncCached::of(6), AsyncCached::of(7), AsyncCached::of(8), AsyncCached::of(9))
            ->map(static fn (int $v1, int $v2, int $v3, int $v4, int $v5, int $v6, int $v7, int $v8, int $v9): int => $v1 + $v2 + $v3 + $v4 + $v5 + $v6 + $v7 + $v8 + $v9);
        self::assertSame(45, $result->value());
        self::assertEquals($metadata, $result->toCached()->metadata);
    }

}
