<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint\Rule;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\Rule\AutoTtlWithoutUpstreamRule;
use Magix\Cache\Cli\Lint\Severity;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutoTtlWithoutUpstreamRule::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(Diagnostic::class)]
#[UsesClass(PolicyDeclaration::class)]
final class AutoTtlWithoutUpstreamRuleTest extends TestCase
{
    public function testCheckReportsAnInheritedTtlWithoutAnySource(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                policy: new PolicyDeclaration(PolicySource::MethodAttribute, Ttl::Auto),
            ),
            new CacheEffect(),
        );

        $diagnostics = (new AutoTtlWithoutUpstreamRule())->check($node, new Catalog([]));

        self::assertCount(1, $diagnostics);
        self::assertSame('auto-ttl-without-upstream', $diagnostics[0]->rule);
        self::assertSame(Severity::Error, $diagnostics[0]->severity);
    }

    public function testCheckAcceptsExpirationsFromStrategiesAndDependencies(): void
    {
        $rule = new AutoTtlWithoutUpstreamRule();
        $policy = new PolicyDeclaration(PolicySource::MethodAttribute, Ttl::Auto);
        $inherited = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31, $policy),
            new CacheEffect(ttl: 20),
        );
        $dynamic = new CacheNode(
            new BoundaryDeclaration('App\FeedQuery', 'execute', 'src/FeedQuery.php', 12, $policy, hasStrategy: true),
            new CacheEffect(),
        );

        self::assertSame([], $rule->check($inherited, new Catalog([])));
        self::assertSame([], $rule->check($dynamic, new Catalog([])));
    }
}
