<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\StrategyArgument;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\ParameterQuery;

#[CoversClass(StrategyArgument::class)]
#[UsesNamespace('Magix\Cache')]
final class StrategyArgumentTest extends TestCase
{
    public function testAttributeIsReadFromTheAnnotatedParameter(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'both');
        $invocation = $definition->invocation([20], static fn (): Cached => Cached::of('origin'));

        self::assertNotNull($invocation->strategy);
    }
}
