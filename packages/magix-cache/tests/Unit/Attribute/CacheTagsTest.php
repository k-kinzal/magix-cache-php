<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\CacheTags;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\ParameterQuery;

#[CoversClass(CacheTags::class)]
#[UsesNamespace('Magix\Cache')]
final class CacheTagsTest extends TestCase
{
    public function testAttributeIsReadFromTheAnnotatedParameter(): void
    {
        $definition = (new CacheDefinitionResolver())->resolve(new ParameterQuery(), 'fetch');
        $invocation = $definition->invocation([30, ['dynamic']], static fn (): Cached => Cached::of('origin'));

        self::assertSame(['dynamic'], $invocation->policy->tags);
    }
}
