<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Declaration\ConstantCatalog;
use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\BinaryOp\BitwiseAnd;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\Mod;
use PhpParser\Node\Expr\BinaryOp\Mul;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LiteralReader::class)]
#[UsesClass(ConstantCatalog::class)]
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

    public function testValueFoldsTheOperatorsAConstantExpressionMayUse(): void
    {
        $reader = new LiteralReader();

        self::assertSame(65, $reader->value(new Plus(new Int_(60), new Int_(5))));
        self::assertSame(300, $reader->value(new Mul(new Int_(60), new Int_(5))));
        self::assertSame('v7', $reader->value(new Concat(new String_('v'), new Int_(7))));
    }

    public function testValueResolvesAConstantDeclaredInAnotherClass(): void
    {
        $reader = new LiteralReader(new ConstantCatalog(['App\Config' => ['TTL' => new Int_(300)]]));

        self::assertSame(300, $reader->value(new ClassConstFetch(new Name('App\Config'), 'TTL')));
    }

    public function testValueResolvesAConstantDefinedByAnotherConstant(): void
    {
        $reader = new LiteralReader(new ConstantCatalog([
            'App\Config' => ['TTL' => new Mul(new ClassConstFetch(new Name('App\Base'), 'UNIT'), new Int_(5))],
            'App\Base' => ['UNIT' => new Int_(60)],
        ]));

        self::assertSame(300, $reader->value(new ClassConstFetch(new Name('App\Config'), 'TTL')));
    }

    public function testValueRefusesToFollowAConstantCycle(): void
    {
        $reader = new LiteralReader(new ConstantCatalog([
            'A' => ['X' => new ClassConstFetch(new Name('B'), 'Y')],
            'B' => ['Y' => new ClassConstFetch(new Name('A'), 'X')],
        ]));

        self::assertSame(LiteralReader::UNRESOLVED, $reader->value(new ClassConstFetch(new Name('A'), 'X')));
    }

    public function testValueMarksADivisionByZeroUnresolvedInsteadOfFailing(): void
    {
        self::assertSame(
            LiteralReader::UNRESOLVED,
            (new LiteralReader())->value(new \PhpParser\Node\Expr\BinaryOp\Div(new Int_(60), new Int_(0))),
        );
    }

    public function testBinaryFoldsArithmeticAndConcatenation(): void
    {
        $reader = new LiteralReader();

        self::assertSame(65, $reader->binary(new Plus(new Int_(60), new Int_(5))));
        self::assertSame('v7', $reader->binary(new Concat(new String_('v'), new Int_(7))));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->binary(new Plus(new String_('a'), new Int_(1))));
    }

    public function testConcatenateJoinsScalarsAndRefusesOtherValues(): void
    {
        $reader = new LiteralReader();

        self::assertSame('v7', $reader->concatenate('v', 7));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->concatenate('v', [7]));
    }

    public function testIntegerOperatorRefusesFloatsAndTheDivisionPhpRejects(): void
    {
        $reader = new LiteralReader();
        $modulo = new Mod(new Int_(62), new Int_(60));

        self::assertSame(2, $reader->integerOperator($modulo, 62, 60));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->integerOperator($modulo, 62, 0));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->integerOperator($modulo, 62.5, 60));
        self::assertSame(4, $reader->integerOperator(new BitwiseAnd(new Int_(12), new Int_(5)), 12, 5));
    }

    public function testGlobalConstantReadsLiteralsAndDeclaredConstants(): void
    {
        $reader = new LiteralReader(new ConstantCatalog(globals: ['App\\WINDOW' => new Int_(45)]));

        self::assertTrue($reader->globalConstant(new ConstFetch(new Name('true'))));
        self::assertSame(45, $reader->globalConstant(new ConstFetch(new Name('App\\WINDOW'))));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->globalConstant(new ConstFetch(new Name('App\\MISSING'))));
    }

    public function testFollowRefusesAReferenceAlreadyBeingResolved(): void
    {
        $reader = new LiteralReader();

        self::assertSame(300, $reader->follow('App\\Config::TTL', new Int_(300)));
        self::assertSame(
            LiteralReader::UNRESOLVED,
            $reader->follow('App\\Config::TTL', new Int_(300), ['App\\Config::TTL' => true]),
        );
    }
}
