<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Deriver\Value\Term;
use Magix\Cache\Cli\Reader\ExpressionDeriver;
use Magix\Cache\Cli\Reader\LiteralReader;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Int_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressionDeriver::class)]
final class ExpressionDeriverTest extends TestCase
{
    public function testValueKeepsExplicitInputsIsolatedAcrossRepeatedQueries(): void
    {
        $reader = new ExpressionDeriver();
        $expression = new Plus(new Variable('ttl'), new Int_(5));

        self::assertSame(35, $reader->value($expression, ['ttl' => 30]));
        self::assertSame(65, $reader->value($expression, ['ttl' => 60]));
        self::assertSame(35, $reader->value($expression, ['ttl' => 30]));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->value($expression));
    }

    public function testSourceRejectsConcreteValuesThatDoNotDescribeEveryOutcome(): void
    {
        $reader = new ExpressionDeriver();

        $arguments = [Term::parameter('flag', 'bool')];
        self::assertSame(LiteralReader::UNRESOLVED, $reader->source('<?php function resolve(bool $flag) { return $flag ? 30 : 60; }', $arguments));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->source('<?php function resolve(bool $flag) { if ($flag) { throw new RuntimeException(); } return 30; }', $arguments));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->source('<?php function resolve() { unknown(); return 30; }'));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->source('<?php function resolve() { return $missing; }'));
    }

    public function testSourceDistinguishesConcreteNullFromAnUnresolvedResult(): void
    {
        $reader = new ExpressionDeriver();

        self::assertNull($reader->source('<?php function resolve() { return null; }'));
        self::assertSame([null, false, 0, ''], $reader->source('<?php function resolve() { return [null, false, 0, ""]; }'));
    }
}
