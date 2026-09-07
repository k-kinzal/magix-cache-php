<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Parameter;

use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\Parameter\CallArguments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\KeyQuery;
use Tests\Fixture\ParameterQuery;

#[CoversClass(CallArguments::class)]
#[UsesNamespace('Magix\Cache')]
final class CallArgumentsTest extends TestCase
{
    public function testBindNormalizesNamedArgumentsAndPreservesRawVariadicKeys(): void
    {
        $definitions = new CacheDefinitionResolver();
        $context = $definitions->resolve(new ParameterQuery(), 'fetch')->keyContext(['visibility' => Visibility::Private]);

        self::assertSame(30, $context->arguments['ttl']);
        self::assertSame(Visibility::Private, $context->arguments['visibility']);
        $variadic = $definitions->resolve(new KeyQuery(), 'execute')->keyContext([2, 'trace', 'positional', 'label' => 'named']);

        self::assertSame(['viewer' => 'even', 'rest[2]' => 'positional', 'rest[label]' => 'named'], $variadic->arguments);
    }

    public function testDefaultValueUsesTheDeclaredEnumDefault(): void
    {
        $context = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'fetch')->keyContext([]);

        self::assertSame(Visibility::Shared, $context->arguments['visibility']);
    }
}
