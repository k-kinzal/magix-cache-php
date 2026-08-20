<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Reader\TypeReader;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeReader::class)]
final class TypeReaderTest extends TestCase
{
    public function testClassNameUnwrapsNullableClassTypes(): void
    {
        $reader = new TypeReader();

        self::assertSame('App\ProductQuery', $reader->className(new NullableType(new Name('App\ProductQuery'))));
        self::assertNull($reader->className(new Identifier('int')));
        self::assertNull($reader->className(new Name('self')));
        self::assertNull($reader->className(null));
    }

    public function testLabelRendersEveryWrittenTypeForm(): void
    {
        $reader = new TypeReader();

        self::assertSame('int', $reader->label(new Identifier('int')));
        self::assertSame('?App\ProductQuery', $reader->label(new NullableType(new Name('App\ProductQuery'))));
        self::assertSame('int|string', $reader->label(new UnionType([new Identifier('int'), new Identifier('string')])));
        self::assertSame('A&B', $reader->label(new IntersectionType([new Name('A'), new Name('B')])));
        self::assertNull($reader->label(null));
    }
}
