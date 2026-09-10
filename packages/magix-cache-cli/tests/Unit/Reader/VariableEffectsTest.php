<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Declaration\MetadataFlow;
use Magix\Cache\Cli\Reader\VariableEffects;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Expression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariableEffects::class)]
#[UsesClass(MetadataFlow::class)]
final class VariableEffectsTest extends TestCase
{
    public function testWrittenFollowsAnAppendToItsVariable(): void
    {
        $reader = new VariableEffects();
        $append = new Expression(new Assign(new ArrayDimFetch(new Variable('items')), new Int_(1)));

        self::assertSame(['items'], $reader->written($append));
    }

    public function testTargetNamesTheVariableOneWriteChanges(): void
    {
        $reader = new VariableEffects();

        self::assertSame('items', $reader->target(new Assign(new ArrayDimFetch(new Variable('items')), new Int_(1))));
        self::assertNull($reader->target(new Int_(1)));
    }

    public function testRootResolvesNestedAssignmentTargets(): void
    {
        $reader = new VariableEffects();

        self::assertSame('items', $reader->root(new ArrayDimFetch(new Variable('items'))));
        self::assertSame('holder', $reader->root(new PropertyFetch(new Variable('holder'), 'field')));
        self::assertNull($reader->root(new Int_(1)));
    }

    public function testReadsExcludesThePlainAssignmentDestination(): void
    {
        $reader = new VariableEffects();
        $statement = new Expression(new Assign(new Variable('target'), new Variable('source')));

        self::assertSame(['source'], $reader->reads($statement));
    }

    public function testAliasesReportsAReferenceBinding(): void
    {
        $reader = new VariableEffects();

        self::assertTrue($reader->aliases(new Expression(new AssignRef(new Variable('alias'), new Variable('value')))));
        self::assertFalse($reader->aliases(new Expression(new Assign(new Variable('copy'), new Variable('value')))));
    }

    public function testUnbindDropsEveryBindingAStatementWrites(): void
    {
        $reader = new VariableEffects();
        $variables = ['items' => new MetadataFlow('none'), 'kept' => new MetadataFlow('none')];
        $append = new Expression(new Assign(new ArrayDimFetch(new Variable('items')), new Int_(1)));

        self::assertSame(['kept'], array_keys($reader->unbind($append, $variables)));
    }

    public function testExtendKeepsEveryValueAnArrayVariableMayHold(): void
    {
        $reader = new VariableEffects();
        $first = new MetadataFlow('call', target: 'A::run');
        $second = new MetadataFlow('call', target: 'B::run');

        $one = $reader->extend(null, $first);
        $two = $reader->extend($one, $second);

        self::assertSame('collection', $one->kind);
        self::assertSame([$first], $one->inputs);
        self::assertSame([$first, $second], $two->inputs);
        self::assertSame($two, $reader->extend($two, $second));
    }

    public function testWriteRecognizesEveryFormOfWrite(): void
    {
        $effects = new VariableEffects();

        self::assertTrue($effects->write(new Assign(new Variable('a'), new Int_(1))));
        self::assertTrue($effects->write(new AssignRef(new Variable('a'), new Variable('b'))));
        self::assertTrue($effects->write(new PostInc(new Variable('a'))));
        self::assertFalse($effects->write(new Variable('a')));
    }

    public function testWritesReportsAWriteAnywhereInsideAnExpression(): void
    {
        $effects = new VariableEffects();
        $call = new FuncCall(new Name('f'), [new Arg(new Assign(new Variable('a'), new Int_(1)))]);

        self::assertTrue($effects->writes($call));
        self::assertFalse($effects->writes(new FuncCall(new Name('f'), [new Arg(new Variable('a'))])));
    }
}
