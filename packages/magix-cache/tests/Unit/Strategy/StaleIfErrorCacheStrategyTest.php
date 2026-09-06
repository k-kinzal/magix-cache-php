<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\StaleIfErrorCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixture\AnsweringStrategy;
use Tests\Fixture\FailingStrategy;
use Tests\Fixture\UpstreamUnavailable;
use Throwable;

#[CoversClass(StaleIfErrorCacheStrategy::class)]
final class StaleIfErrorCacheStrategyTest extends TestCase
{
    public function testFetchServesTheRetainedCandidateOnAnAcceptedFailure(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(
            maxAge: 300,
            accepts: static fn (Throwable $error): bool => $error instanceof UpstreamUnavailable,
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $stale = Cached::of('stale', new CacheMetadata(expiresAt: 90.0));
        $operation->retainStale($stale, 400.0);

        $result = $strategy->fetch($operation, NextCacheStrategy::of(new FailingStrategy(new UpstreamUnavailable('down'))));

        self::assertSame('stale', $result->value());
        self::assertSame(90.0, $result->metadata->expiresAt, 'a served candidate keeps its expired expiration');
        self::assertTrue($operation->storeSuppressed());
    }

    public function testFetchRethrowsAFailureTheJudgementDoesNotAccept(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(
            maxAge: 300,
            accepts: static fn (Throwable $error): bool => $error instanceof UpstreamUnavailable,
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $operation->retainStale(Cached::of('stale', new CacheMetadata(expiresAt: 90.0)), 400.0);

        $this->expectException(RuntimeException::class);
        $strategy->fetch($operation, NextCacheStrategy::of(new FailingStrategy(new RuntimeException('other'))));
    }

    public function testFetchRethrowsWhenNoEligibleCandidateExists(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(
            maxAge: 300,
            accepts: static fn (Throwable $error): bool => true,
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $this->expectException(UpstreamUnavailable::class);
        $strategy->fetch($operation, NextCacheStrategy::of(new FailingStrategy(new UpstreamUnavailable('down'))));
    }

    public function testSetExtendsRetentionWithoutChangingTheExpiration(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(maxAge: 300, accepts: static fn (Throwable $error): bool => true);
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('value'));
        $result = Cached::of('value', new CacheMetadata(expiresAt: 160.0));

        $strategy->set($operation, $result, NextCacheStrategy::of($terminal));

        self::assertSame(460.0, $operation->retention());
        self::assertSame(160.0, $terminal->stored?->metadata->expiresAt);
    }

    public function testSetLeavesAnUnconstrainedResultWithoutRetention(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(maxAge: 300, accepts: static fn (Throwable $error): bool => true);
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $terminal = new AnsweringStrategy(hit: null, fetched: Cached::of('value'));

        $strategy->set($operation, Cached::of('value'), NextCacheStrategy::of($terminal));

        self::assertNull($operation->retention());
    }

    public function testGetDelegatesTheLookupUnchanged(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(maxAge: 300, accepts: static fn (Throwable $error): bool => true);
        $terminal = new AnsweringStrategy(hit: Cached::of('hit'), fetched: Cached::of('value'));
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertSame('hit', $strategy->get($operation, NextCacheStrategy::of($terminal))?->value());
    }
}
