<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LiteralReader::class)]
final class LiteralReaderTest extends TestCase
{
    public function testValueReadsScalarsAndConstants(): void
    {
        $reader = new LiteralReader();

        self::assertSame(20, $reader->value(new Int_(20)));
        self::assertSame('page', $reader->value(new String_('page')));
        self::assertSame(-5, $reader->value(new UnaryMinus(new Int_(5))));
        self::assertTrue($reader->value(new ConstFetch(new Name('true'))));
        self::assertNull($reader->value(new ConstFetch(new Name('null'))));
    }

    public function testValueMarksExpressionsItCannotRead(): void
    {
        self::assertSame(LiteralReader::UNRESOLVED, (new LiteralReader())->value(new Variable('ttl')));
    }

    public function testConstantReadsPolicyEnumCasesAndClassNames(): void
    {
        $reader = new LiteralReader();

        self::assertSame(Ttl::Auto, $reader->constant(new ClassConstFetch(new Name(Ttl::class), 'Auto')));
        self::assertSame(Visibility::Private, $reader->constant(new ClassConstFetch(new Name(Visibility::class), 'Private')));
        self::assertSame('App\Reducer', $reader->constant(new ClassConstFetch(new Name('App\Reducer'), 'class')));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->constant(new ClassConstFetch(new Name('App\Limits'), 'DEFAULT')));
    }

    public function testItemsReadListsAndRejectUnreadableEntries(): void
    {
        $reader = new LiteralReader();
        $tags = new Array_([new ArrayItem(new String_('page')), new ArrayItem(new String_('product'))]);
        $dynamic = new Array_([new ArrayItem(new Variable('tag'))]);

        self::assertSame(['page', 'product'], $reader->items($tags));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->items($dynamic));
    }
}
