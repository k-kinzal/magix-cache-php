<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Strategy\StrategyArguments;
use Magix\Cache\Strategy\StrategyDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\CacheHandlers;
use Tests\Fixture\StatefulStrategy;

#[CoversClass(StrategyDefinition::class)]
#[UsesClass(StrategyArguments::class)]
#[UsesClass(\Magix\Cache\Strategy\ComposedCacheStrategy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesNamespace('Magix\Cache')]
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
        $key = 'key';
        $next = new CacheHandlers(null, Cached::of('value'));
        $first->get($key, $next->get(...));
        $firstResult = $first->fetch($key, $next->fetch(...));
        $second->get($key, $next->get(...));
        $secondResult = $second->fetch($key, $next->fetch(...));


        self::assertSame(['lookup:key', 'state:1:1'], $firstResult->metadata->tags);
        self::assertSame($firstResult->metadata->tags, $secondResult->metadata->tags);
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
