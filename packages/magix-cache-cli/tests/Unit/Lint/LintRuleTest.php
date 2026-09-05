<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Rule\MissingPolicyRule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LintRuleTest extends TestCase
{
    public function testCheckReturnsDiagnosticsForOneBoundary(): void
    {
        $rule = new MissingPolicyRule();
        $node = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31),
            new CacheEffect(),
        );

        $diagnostics = $rule->check($node, new Catalog([]));

        self::assertCount(1, $diagnostics);
        self::assertSame('missing-policy', $diagnostics[0]->rule);
        self::assertSame('App\\PageQuery::execute', $diagnostics[0]->boundary);
    }
}
