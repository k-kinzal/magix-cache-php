<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\CacheHandlers;

#[CoversClass(KeySpreadExpirationStrategy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesClass(\Magix\Cache\Strategy\CacheRead::class)]
#[UsesClass(CacheWrite::class)]
#[UsesClass(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class)]
#[UsesNamespace('Magix\Cache')]
final class KeySpreadExpirationStrategyTest extends TestCase
{
    public function testFetchMeetsADeterministicConstraintWithinTheRange(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 30, maximum: 60, clock: new \Tests\Fixture\MutableClock(100.0));
        $handlers = new CacheHandlers(hit: null, fetched: Cached::of('value'));
        $first = 'key';
        $second = 'key';

        $fetched1 = $strategy->fetch($first, $handlers->fetch(...));

        $expiresAt = $fetched1->metadata->expiresAt;

        self::assertNotNull($expiresAt);
        self::assertGreaterThanOrEqual(130.0, $expiresAt);
        self::assertLessThanOrEqual(160.0, $expiresAt);
        $fetched2 = $strategy->fetch($second, $handlers->fetch(...));

        self::assertSame($expiresAt, $fetched2->metadata->expiresAt);
    }

    public function testFetchOverridesAnExistingExpiration(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 30, maximum: 60, clock: new \Tests\Fixture\MutableClock(100.0));
        $handlers = new CacheHandlers(hit: null, fetched: Cached::of('value', new CacheMetadata(expiresAt: 110.0)));
        $key = 'key';

        $fetched3 = $strategy->fetch($key, $handlers->fetch(...));

        self::assertGreaterThanOrEqual(130.0, $fetched3->metadata->expiresAt);
        self::assertLessThanOrEqual(160.0, $fetched3->metadata->expiresAt);
    }

    public function testFetchPinsTheConstraintWhenTheBoundsAreEqual(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 60, maximum: 60, clock: new \Tests\Fixture\MutableClock(100.0));
        $handlers = new CacheHandlers(hit: null, fetched: Cached::of('value'));
        $key = 'key';

        $fetched4 = $strategy->fetch($key, $handlers->fetch(...));

        self::assertSame(160.0, $fetched4->metadata->expiresAt);
    }

    public function testFetchOverridesExpirationWithoutClassifyingTheValueSource(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 30, maximum: 60, clock: new \Tests\Fixture\MutableClock(100.0));
        $stale = Cached::of('stale', new CacheMetadata(expiresAt: 90.0));
        $handlers = new CacheHandlers(hit: null, fetched: $stale);
        $key = 'key';

        $fetched5 = $strategy->fetch($key, $handlers->fetch(...));

        self::assertSame('stale', $fetched5->value());
        self::assertGreaterThanOrEqual(130.0, $fetched5->metadata->expiresAt);
    }

    public function testGetDelegatesTheLookupUnchanged(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 30, maximum: 60, clock: new \Tests\Fixture\MutableClock(100.0));
        $handlers = new CacheHandlers(hit: Cached::of('hit', new CacheMetadata(expiresAt: 150.0)), fetched: Cached::of('value'));
        $key = 'key';

        self::assertSame('hit', $strategy->get($key, $handlers->get(...))?->cached->value());
    }

    public function testSetDelegatesTheStoreUnchanged(): void
    {
        $strategy = new KeySpreadExpirationStrategy(minimum: 30, maximum: 60, clock: new \Tests\Fixture\MutableClock(100.0));
        $handlers = new CacheHandlers(hit: null, fetched: Cached::of('value'));
        $key = 'key';
        $result = Cached::of('value', new CacheMetadata(expiresAt: 150.0));

        $strategy->set($key, new CacheWrite($result), $handlers->set(...));

        self::assertSame($result, $handlers->stored?->cached);
        self::assertNull($handlers->stored->retainedUntil);
    }


}
