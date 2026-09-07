<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginResult;
use Magix\Cache\Strategy\StrategyArguments;
use Magix\Cache\Strategy\StrategyDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\AnsweringStrategy;
use Tests\Fixture\StatefulStrategy;

#[CoversClass(StrategyDefinition::class)]
#[UsesClass(StrategyArguments::class)]
#[UsesClass(\Magix\Cache\Strategy\ComposedCacheStrategy::class)]
#[UsesClass(CacheOperation::class)]
#[UsesClass(NextCacheStrategy::class)]
#[UsesClass(OriginResult::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class StrategyDefinitionTest extends TestCase
{
    public function testOfDefersConstructionAndInstantiateCreatesIndependentState(): void
    {
        $definition = StrategyDefinition::of(StatefulStrategy::class, label: 'custom');
        $first = $definition->instantiate();
        $second = $definition->instantiate();

        self::assertInstanceOf(StatefulStrategy::class, $first);
        self::assertInstanceOf(StatefulStrategy::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame('custom', $first->label);
        $first->lookups = 7;
        self::assertSame(0, $second->lookups);
    }

    public function testInstantiateBuildsEveryNestedStrategyAgain(): void
    {
        $child = StrategyDefinition::of(StatefulStrategy::class, label: 'child');
        $definition = StrategyDefinition::of(StatefulStrategy::class, child: $child);
        $first = $definition->instantiate();
        $second = $definition->instantiate();

        self::assertInstanceOf(StatefulStrategy::class, $first);
        self::assertInstanceOf(StatefulStrategy::class, $second);
        self::assertInstanceOf(StatefulStrategy::class, $first->child);
        self::assertInstanceOf(StatefulStrategy::class, $second->child);
        self::assertNotSame($first->child, $second->child);
    }

    public function testComposeKeepsEveryNestedLeafIndependentAcrossExecutions(): void
    {
        $leaf = StrategyDefinition::of(StatefulStrategy::class);
        $definition = StrategyDefinition::compose($leaf, StrategyDefinition::compose($leaf, $leaf));
        $first = $definition->instantiate();
        $second = $definition->instantiate();
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $next = NextCacheStrategy::of(new AnsweringStrategy(null, Cached::of('value')));
        $first->get($operation, $next);
        $firstResult = $first->fetch($operation, $next);
        $second->get($operation, $next);
        $secondResult = $second->fetch($operation, $next);

        self::assertInstanceOf(OriginResult::class, $firstResult);
        self::assertInstanceOf(OriginResult::class, $secondResult);
        self::assertSame(['lookup:key', 'state:1:1'], $firstResult->cached->metadata->tags);
        self::assertSame($firstResult->cached->metadata->tags, $secondResult->cached->metadata->tags);
    }

    public function testDefinitionDetachesReferencedConfiguration(): void
    {
        $value = 'original';
        $definition = StrategyDefinition::of(StatefulStrategy::class, settings: ['nested' => ['value' => &$value]]);
        $value = 'changed';
        $first = $definition->instantiate();
        self::assertInstanceOf(StatefulStrategy::class, $first);
        self::assertSame(['nested' => ['value' => 'original']], $first->settings);
        $first->settings['nested'] = ['value' => 'mutated'];

        $second = $definition->instantiate();
        self::assertInstanceOf(StatefulStrategy::class, $second);
        self::assertSame(['nested' => ['value' => 'original']], $second->settings);
    }
}
