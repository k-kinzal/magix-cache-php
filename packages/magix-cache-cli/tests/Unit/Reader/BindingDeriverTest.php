<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Reader\BindingDeriver;
use Magix\Cache\Cli\Reader\ExpressionDeriver;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BindingDeriver::class)]
#[UsesClass(ExpressionDeriver::class)]
final class BindingDeriverTest extends TestCase
{
    public function testBoundRetainsTheLeafIdentityAndLocationWithoutInvokingIt(): void
    {
        $leaf = new FuncCall(new Name('unavailableApplicationFactory'), attributes: ['startLine' => 42]);
        $bindings = ['recipe' => new Variable('alias'), 'alias' => $leaf];

        $result = (new BindingDeriver())->bound(new Variable('recipe'), $bindings, 8);

        self::assertSame($leaf, $result);
        self::assertSame(42, $result->getStartLine());
    }
}
