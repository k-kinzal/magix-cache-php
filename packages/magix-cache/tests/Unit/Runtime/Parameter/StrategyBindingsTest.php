<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Parameter;

use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Runtime\Parameter\StrategyBindings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\ParameterStrategy;

#[CoversClass(StrategyBindings::class)]
#[UsesNamespace('Magix\Cache')]
final class StrategyBindingsTest extends TestCase
{
    public function testValidateCombinesDistinctStaticAndParameterDestinations(): void
    {
        $bindings = new StrategyBindings();
        $use = new UseStrategy(ParameterStrategy::class, label: 'static');
        $bindings->validate($use, ['ttl' => 'lifetime']);

        self::assertSame(['label' => 'static'], $use->arguments, 'validation retains only the static declaration');
    }

    public function testFactoryFindsThePublicStaticConstructionMethod(): void
    {
        $factory = (new StrategyBindings())->factory(new UseStrategy(ParameterStrategy::class));

        self::assertSame('create', $factory->getName());
        self::assertSame('ttl', $factory->getParameters()[0]->getName());
    }
}
