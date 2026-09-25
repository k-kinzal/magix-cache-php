<?php

declare(strict_types=1);

namespace Tests\Unit\Async;

use Error;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use Magix\Cache\Async\Promise;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixture\PendingResult;
use Tests\Fixture\UpstreamUnavailable;

#[CoversClass(Promise::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Magix\Cache')]
final class PromiseTest extends TestCase
{
    public function testFromGuzzleImportsWithoutWaiting(): void
    {
        $pending = new PendingResult(5);
        $promise = Promise::fromGuzzle(new FulfilledPromise('start'))->then(static fn (): Promise => $pending->promise())->then(static fn (int $x): int => $x * 2);
        self::assertSame(0, $pending->waits);
        self::assertSame(10, $promise->wait());
        self::assertSame(10, $promise->wait());
        self::assertSame(1, $pending->waits);
    }

    public function testRecoverHandlesDelayedRejections(): void
    {
        $pending = new PendingResult('unused');
        $error = new UpstreamUnavailable('down');
        $promise = $pending->promise()->recover(static fn (RuntimeException $reason): string => $reason->getMessage());
        $pending->fail($error);
        self::assertSame('down', $promise->wait());
    }

    public function testRejectedPreservesFailureIdentity(): void
    {
        $error = new UpstreamUnavailable('down');
        $promise = Promise::rejected($error)->then(static fn (mixed $value): mixed => $value);
        $this->expectExceptionObject($error);
        $promise->wait();
    }

    public function testForeignProgrammingFailuresAreNeverGivenToRuntimeRecovery(): void
    {
        $error = new Error('foreign callback bug');
        $recovered = false;
        $promise = Promise::fromGuzzle(new RejectedPromise($error))->recover(static function () use (&$recovered): string {
            $recovered = true;

            return 'wrong';
        })->then(static fn (mixed $value): mixed => $value, static fn (mixed $reason): mixed => $reason);
        self::assertSame($error, $promise->wait());
        self::assertFalse($recovered);
    }

    public function testCallSchedulesWithoutBlocking(): void
    {
        $calls = 0;
        $promise = Promise::call(static function () use (&$calls): string {
            ++$calls;

            return 'value';
        });
        self::assertSame(0, $calls);
        self::assertSame('value', $promise->wait());
        self::assertSame(1, $calls);
        self::assertSame('unit', Promise::resolved()->then(static fn (): string => 'unit')->wait());
    }
    public function testThenAdoptsTheNextPendingResult(): void
    {
        $source = new PendingResult('later');
        $result = Promise::resolved(1)->then(static fn (): Promise => $source->promise());
        self::assertSame(0, $source->waits);
        self::assertSame('later', $result->wait());
        self::assertSame(1, $source->waits);
    }

    public function testAllWaitsOnlyWhenTheCombinedResultIsRequested(): void
    {
        $a = new PendingResult(1);
        $b = new PendingResult(2);
        $result = Promise::all([$a->promise(), $b->promise()]);
        self::assertSame(0, $a->waits + $b->waits);
        self::assertSame([1, 2], $result->wait());
        self::assertSame(2, $a->waits + $b->waits);
    }

    public function testBridgeSubscribesWithoutCallingItsWaitDriver(): void
    {
        $source = new PendingResult(8);
        $cached = \Magix\Cache\AsyncCached::fromPromise($source->promise());
        $bridge = Promise::bridge($cached->toCached(...), $cached->subscribe(...));
        self::assertSame(0, $source->waits);
        self::assertSame(8, $bridge->wait()->value());
        self::assertSame(1, $source->waits);
    }

    public function testResolvedProvidesAUnitToAContinuation(): void
    {
        self::assertSame('unit', Promise::resolved()->then(static fn (): string => 'unit')->wait());
    }

    public function testWaitDrivesTheTransportOnlyOnce(): void
    {
        $source = new PendingResult('done');
        $promise = $source->promise();
        self::assertSame('done', $promise->wait());
        self::assertSame('done', $promise->wait());
        self::assertSame(1, $source->waits);
    }

    public function testBridgeKeepsReentrantRejectionsInsideTheRecoveryChain(): void
    {
        $source = new PendingResult('unused');
        $cached = \Magix\Cache\AsyncCached::fromPromise($source->promise());
        $bridge = Promise::bridge($cached->toCached(...), $cached->subscribe(...))
            ->recover(static fn (): \Magix\Cache\Cached => \Magix\Cache\Cached::of('recovered'));
        $source->fail(new UpstreamUnavailable('down'));
        self::assertSame('recovered', $bridge->wait()->value());
        self::assertSame('recovered', $bridge->wait()->value());
    }

}
