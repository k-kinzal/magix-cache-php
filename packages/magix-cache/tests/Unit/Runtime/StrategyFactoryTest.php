<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Async\Promise;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Observation\CacheEvent;
use Magix\Cache\Runtime\StrategyFactory;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use Magix\Cache\Strategy\StaleIfErrorCacheStrategy;
use Magix\Cache\Strategy\StrategyDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixture\CacheHandlers;
use Tests\Fixture\MutableClock;
use Tests\Fixture\RecordingObserver;
use Tests\Fixture\RecordingStrategy;
use Tests\Fixture\StatefulStrategy;

#[CoversClass(StrategyFactory::class)]
#[UsesNamespace('Magix\Cache')]
final class StrategyFactoryTest extends TestCase
{
    public function testCreateSuppliesTheRuntimeClockThroughNestedDefinitions(): void
    {
        $clock = new MutableClock(100.0);
        $factory = new StrategyFactory($clock, null);
        $definition = StrategyDefinition::compose(StrategyDefinition::compose(
            StrategyDefinition::of(KeySpreadExpirationStrategy::class, 30, 30),
        ));
        $strategy = $definition->instantiate($factory->create(...));
        $clock->advance(7.0);

        $result = $strategy->fetch('key', static fn (): Promise => Promise::resolved(Cached::of('value')))->wait();

        self::assertSame(137.0, $result->metadata->expiresAt);
    }

    public function testCreateSuppliesObservationDirectlyToTheMiddleware(): void
    {
        $observer = new RecordingObserver();
        $factory = new StrategyFactory(new MutableClock(100.0), $observer);
        $strategy = $factory->create(StaleIfErrorCacheStrategy::class, ['maxAge' => 30, 'exceptions' => [RuntimeException::class]]);
        $cached = Cached::of('retained', new CacheMetadata(expiresAt: 90.0));
        $strategy->get('key', static fn (): CacheRead => new CacheRead($cached, 120.0));
        $result = $strategy->fetch('key', static fn (): Cached => throw new RuntimeException('down'))->wait();
        $handlers = new CacheHandlers(null, Cached::of('unused'));
        $strategy->set('key', new CacheWrite($result), $handlers->set(...));

        self::assertSame([CacheEvent::StaleServed], $observer->events);
        self::assertNull($handlers->stored);
    }

    public function testCreatePreservesExplicitConstructorDependencies(): void
    {
        $factory = new StrategyFactory(new MutableClock(100.0), null);
        $strategy = $factory->create(KeySpreadExpirationStrategy::class, [30, 30, new MutableClock(200.0)]);

        self::assertSame(230.0, $strategy->fetch('key', static fn (): Promise => Promise::resolved(Cached::of('value')))->wait()->metadata->expiresAt);
    }

    public function testCreateConstructsIndependentStateWithoutRequiringDependencies(): void
    {
        $factory = new StrategyFactory(new MutableClock(100.0), null);
        $definition = StrategyDefinition::of(StatefulStrategy::class, label: 'state', child: StrategyDefinition::of(RecordingStrategy::class, 'child'));
        $first = $definition->instantiate($factory->create(...));
        $second = $definition->instantiate($factory->create(...));
        self::assertInstanceOf(StatefulStrategy::class, $first);
        self::assertInstanceOf(StatefulStrategy::class, $second);
        $handlers = new CacheHandlers(null, Cached::of('value'));
        $first->get('key', $handlers->get(...));
        $result = $first->fetch('key', $handlers->fetch(...))->wait();
        $first->set('key', new CacheWrite($result), $handlers->set(...));

        self::assertSame([1, 1, 1], [$first->lookups, $first->fetches, $first->stores]);
        self::assertSame([0, 0, 0], [$second->lookups, $second->fetches, $second->stores]);
        self::assertNotSame($first->child, $second->child);
    }
}
