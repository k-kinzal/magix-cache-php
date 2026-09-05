<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint\Rule;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\EffectCalculator;
use Magix\Cache\Cli\Graph\TtlEstimate;
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
#[UsesClass(EffectCalculator::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\DependencyConstraint::class)]
final class AutoTtlWithoutUpstreamRuleTest extends TestCase
{
    public function testCheckReportsAConfirmedUnconstrainedUpstreamAsAnError(): void
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

    public function testCheckKeepsAnUnknownUpstreamConditionalAsANotice(): void
    {
        $child = new CacheNode(
            new BoundaryDeclaration('App\RateQuery', 'execute', 'src/RateQuery.php', 12),
            new CacheEffect(ttl: TtlEstimate::unknown(30, 'a resolver decides')),
        );
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                policy: new PolicyDeclaration(PolicySource::MethodAttribute, Ttl::FromUpstream, maxTtl: 30),
            ),
            new CacheEffect(ttl: TtlEstimate::unknown(30)),
            [$child],
        );

        $diagnostics = (new AutoTtlWithoutUpstreamRule())->check($node, new Catalog([]));

        self::assertCount(1, $diagnostics);
        self::assertSame(Severity::Notice, $diagnostics[0]->severity);
        self::assertStringContainsString('cannot be determined statically', $diagnostics[0]->message);
    }

    public function testCheckAcceptsExpirationsFromDependenciesAndEscapeHatches(): void
    {
        $rule = new AutoTtlWithoutUpstreamRule();
        $policy = new PolicyDeclaration(PolicySource::MethodAttribute, Ttl::Auto);
        $fixed = new PolicyDeclaration(PolicySource::MethodAttribute, 20);
        $child = new CacheNode(
            new BoundaryDeclaration('App\FeedQuery', 'execute', 'src/FeedQuery.php', 12),
            new CacheEffect(ttl: TtlEstimate::known(20)),
        );
        $inherited = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31, $policy),
            new CacheEffect(ttl: TtlEstimate::known(20)),
            [$child],
        );
        $resolving = new CacheNode(
            new BoundaryDeclaration('App\RateQuery', 'execute', 'src/RateQuery.php', 12, $policy, hasDynamicTtl: true),
            new CacheEffect(),
        );
        $supplying = new CacheNode(
            new BoundaryDeclaration('App\UpstreamQuery', 'execute', 'src/UpstreamQuery.php', 12, $policy, suppliesMetadata: true),
            new CacheEffect(),
        );
        $declared = new CacheNode(
            new BoundaryDeclaration('App\StaticQuery', 'execute', 'src/StaticQuery.php', 12, $fixed),
            new CacheEffect(ttl: TtlEstimate::known(20)),
        );

        self::assertSame([], $rule->check($inherited, new Catalog([])));
        self::assertSame([], $rule->check($resolving, new Catalog([])));
        self::assertSame([], $rule->check($supplying, new Catalog([])));
        self::assertSame([], $rule->check($declared, new Catalog([])));
    }
}
