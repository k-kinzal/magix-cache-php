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
    public function testSourceCapturesPrecisionPerSnapshotWithoutChangingTheHost(): void
    {
        $reader = new ExpressionDeriver();
        $source = '<?php function resolve() { return "v" . (1 / 3); }';
        $original = ini_get('precision');

        try {
            ini_set('precision', '3');
            self::assertSame('v0.333', $reader->source($source));
            self::assertSame('3', ini_get('precision'));
            ini_set('precision', '17');
            self::assertSame('v0.33333333333333331', $reader->source($source));
            self::assertSame('17', ini_get('precision'));
            ini_set('precision', '3');
            self::assertSame('v0.333', $reader->source($source));
            self::assertSame('3', ini_get('precision'));
        } finally {
            ini_set('precision', $original);
        }
    }

    public function testSourceDerivesZeroPowersWithoutLeakingHostDeprecations(): void
    {
        $reader = new ExpressionDeriver();

        self::assertSame(INF, $reader->source('<?php function resolve() { return 0 ** -1; }'));
        self::assertSame(-INF, $reader->source('<?php function resolve() { return (-0.0) ** -3; }'));
    }

    public function testValueKeepsExplicitInputsIsolatedAcrossRepeatedQueries(): void
    {
        $reader = new ExpressionDeriver();
        $expression = new Plus(new Variable('ttl'), new Int_(5));

        self::assertSame(35, $reader->value($expression, ['ttl' => 30]));
        self::assertSame(65, $reader->value($expression, ['ttl' => 60]));
        self::assertSame(35, $reader->value($expression, ['ttl' => 30]));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->value($expression));
    }

    public function testSourceRejectsMultipleExceptionalAndResidualCandidates(): void
    {
        $reader = new ExpressionDeriver();

        $arguments = [Term::parameter('flag', 'bool')];
        self::assertSame(LiteralReader::UNRESOLVED, $reader->source('<?php function resolve(bool $flag) { return $flag ? 30 : 60; }', $arguments));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->source('<?php function resolve(bool $flag) { return $flag ? throw new RuntimeException() : 30; }', $arguments));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->source('<?php function resolve() { return unknown(); }'));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->source('<?php function resolve() { return $missing; }'));
    }

    public function testSourceSelectsCandidatesWithoutClaimingExecutionReachability(): void
    {
        $reader = new ExpressionDeriver();

        self::assertSame(30, $reader->source('<?php function resolve() { unknown(); return 30; }'));
        self::assertSame(30, $reader->source('<?php function resolve(bool $flag) { return $flag ? 30 : 30; }', [Term::parameter('flag', 'bool')]));
    }

    public function testSourceDoesNotPromoteKnownNeighborsOrDefaultsOverDynamicInputs(): void
    {
        $reader = new ExpressionDeriver();

        self::assertSame(LiteralReader::UNRESOLVED, $reader->source('<?php function resolve(int $ttl = 30) { return $ttl; }', [Term::parameter('ttl', 'int')]));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->source('<?php function resolve($tag) { return ["known", $tag, "tail"]; }', [Term::parameter('tag')]));
        self::assertSame(LiteralReader::UNRESOLVED, $reader->source('<?php function resolve() { return "tag:" . $_GET["tag"]; }'));
    }

    public function testSourceDistinguishesConcreteNullFromAnUnresolvedResult(): void
    {
        $reader = new ExpressionDeriver();

        self::assertNull($reader->source('<?php function resolve() { return null; }'));
        self::assertSame([null, false, 0, ''], $reader->source('<?php function resolve() { return [null, false, 0, ""]; }'));
    }
}
