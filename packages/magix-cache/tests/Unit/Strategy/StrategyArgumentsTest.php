<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Strategy\StrategyArguments;
use Magix\Cache\Strategy\StrategyDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\StatefulStrategy;

#[CoversClass(StrategyArguments::class)]
#[UsesClass(StrategyDefinition::class)]
final class StrategyArgumentsTest extends TestCase
{
    public function testCopyDetachesReferencesAndPreservesConfigurationValues(): void
    {
        $value = 'original';
        $copy = (new StrategyArguments())->copy(['value' => &$value, 'nested' => [null, true, 10, 1.5, Visibility::Shared]]);
        $value = 'changed';

        self::assertSame(['value' => 'original', 'nested' => [null, true, 10, 1.5, Visibility::Shared]], $copy);
    }

    public function testInstantiateResolvesNestedDefinitionsIntoFreshObjects(): void
    {
        $arguments = new StrategyArguments();
        $configuration = ['child' => [StrategyDefinition::of(StatefulStrategy::class)]];
        $first = $arguments->instantiate($configuration);
        $second = $arguments->instantiate($configuration);

        self::assertIsArray($first['child']);
        self::assertIsArray($second['child']);
        self::assertInstanceOf(StatefulStrategy::class, $first['child'][0]);
        self::assertInstanceOf(StatefulStrategy::class, $second['child'][0]);
        self::assertNotSame($first['child'][0], $second['child'][0]);
    }
}
