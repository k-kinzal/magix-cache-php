<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\CacheTtl;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\ParameterQuery;

#[CoversClass(CacheTtl::class)]
#[UsesNamespace('Magix\Cache')]
final class CacheTtlTest extends TestCase
{
    public function testAttributeIsReadFromTheAnnotatedParameter(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'fetch');
        $invocation = $definition->invocation([12], static fn (): Cached => Cached::of('origin'));

        self::assertSame(12, $invocation->parameterTtl);
    }
}
