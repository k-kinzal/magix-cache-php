<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheKey;
use Magix\Cache\Cli\Reader\AttributeReader;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttributeReader::class)]
final class AttributeReaderTest extends TestCase
{
    public function testFindReturnsTheRequestedAttribute(): void
    {
        $attribute = new Attribute(new Name(Cache::class));
        $groups = [new AttributeGroup([new Attribute(new Name(CacheKey::class)), $attribute])];

        self::assertSame($attribute, (new AttributeReader())->find($groups, Cache::class));
    }

    public function testFindReturnsNullWhenTheAttributeIsAbsent(): void
    {
        $groups = [new AttributeGroup([new Attribute(new Name(CacheKey::class))])];

        self::assertNull((new AttributeReader())->find($groups, Cache::class));
    }
}
