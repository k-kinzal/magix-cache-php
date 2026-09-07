<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Parameter;

use Magix\Cache\Cached;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\Parameter\ParameterBinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\ParameterQuery;

#[CoversClass(ParameterBinding::class)]
#[UsesNamespace('Magix\Cache')]
final class ParameterBindingTest extends TestCase
{
    public function testReadAllowsOneParameterToSupplyBothAConstraintAndAFactoryArgument(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'both');
        $invocation = $definition->invocation([15], static fn (): Cached => Cached::of('origin'));

        self::assertSame(15, $invocation->parameterTtl);
        self::assertNotNull($invocation->strategy);
    }
}
