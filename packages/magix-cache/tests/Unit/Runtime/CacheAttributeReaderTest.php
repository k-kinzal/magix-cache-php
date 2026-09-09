<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Runtime\CacheAttributeReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\ChildDeclaredQuery;
use Tests\Fixture\DeclaredQuery;
use Tests\Fixture\PlainQuery;

#[CoversClass(CacheAttributeReader::class)]
#[UsesClass(Cache::class)]
#[UsesClass(StaleIfError::class)]
#[UsesClass(\Magix\Cache\CachePolicy::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
final class CacheAttributeReaderTest extends TestCase
{
    public function testReadPrefersTheMethodDeclarationAsAWhole(): void
    {
        $declaration = (new CacheAttributeReader())->read(new DeclaredQuery(), 'viaMethod', Cache::class);

        self::assertNotNull($declaration);
        self::assertSame(30, $declaration->ttl);
        self::assertNull($declaration->tags);
    }

    public function testReadFallsBackToTheConcreteClassDeclaration(): void
    {
        $declaration = (new CacheAttributeReader())->read(new DeclaredQuery(), 'viaClass', Cache::class);

        self::assertNotNull($declaration);
        self::assertSame(60, $declaration->ttl);
        self::assertSame(['declared'], $declaration->tags);
    }

    public function testReadReturnsNullWhenNothingIsDeclared(): void
    {
        self::assertNull((new CacheAttributeReader())->read(new PlainQuery(), 'execute', Cache::class));
    }

    public function testReadNeverInheritsAParentClassDeclaration(): void
    {
        self::assertNull((new CacheAttributeReader())->read(new ChildDeclaredQuery(), 'classOnly', Cache::class));
    }

    public function testReadKeepsTheAttributesOfAnInheritedMethod(): void
    {
        $declaration = (new CacheAttributeReader())->read(new ChildDeclaredQuery(), 'inherited', Cache::class);

        self::assertNotNull($declaration);
        self::assertSame(25, $declaration->ttl);
        self::assertSame('inherited', $declaration->version);
    }
}
