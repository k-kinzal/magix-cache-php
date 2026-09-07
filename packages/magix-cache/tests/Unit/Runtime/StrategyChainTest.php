<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Runtime\CacheInvocation;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\GuardedCache;
use Magix\Cache\Runtime\StrategyChain;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\OriginResult;
use Magix\Cache\Strategy\StrategyDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\StatefulStrategy;

#[CoversClass(StrategyChain::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CachePolicy::class)]
#[UsesClass(CacheInvocation::class)]
#[UsesClass(CacheKeyContext::class)]
#[UsesClass(GuardedCache::class)]
#[UsesClass(StrategyDefinition::class)]
#[UsesClass(\Magix\Cache\Strategy\StrategyArguments::class)]
#[UsesClass(\Magix\Cache\Strategy\NextCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Runtime\TerminalCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Runtime\CacheEntryConverter::class)]
#[UsesClass(CacheOperation::class)]
#[UsesClass(CacheWrite::class)]
#[UsesClass(OriginResult::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class StrategyChainTest extends TestCase
{
    public function testBindUsesOneInstanceAcrossStagesAndANewOneAcrossExecutions(): void
    {
        $invocation = new CacheInvocation(
            new CacheKeyContext('', 'Q', 'Q', 'execute', [], '1', 'f'),
            new CachePolicy(ttl: 0),
            static fn (): Cached => Cached::of('origin'),
            strategy: StrategyDefinition::of(StatefulStrategy::class),
        );
        $builder = new StrategyChain(new GuardedCache(new MemoryCache()), null, null);
        $first = $builder->bind($invocation);
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $first->get($operation);
        $result = $first->fetch($operation);
        self::assertInstanceOf(OriginResult::class, $result);
        $first->set($operation, new CacheWrite($result->cached));
        $strategy = $first->strategy();
        self::assertInstanceOf(StatefulStrategy::class, $strategy);
        self::assertSame(1, $strategy->lookups);
        self::assertSame(1, $strategy->fetches);
        self::assertSame(1, $strategy->stores);

        $second = $builder->bind($invocation)->strategy();
        self::assertInstanceOf(StatefulStrategy::class, $second);
        self::assertNotSame($strategy, $second);
        self::assertSame(0, $second->lookups);
        self::assertSame(0, $second->fetches);
        self::assertSame(0, $second->stores);
    }
}
