<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\ParameterConfiguration;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Cli\Graph\EffectCalculator;
use Magix\Cache\Cli\Graph\LocalOverrides;
use Magix\Cache\Cli\Graph\StrategyEffect;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlInterval;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalOverrides::class)]
#[UsesNamespace('Magix\Cache\Cli')]
#[UsesClass(Visibility::class)]
final class LocalOverridesTest extends TestCase
{
    /**
     * @return iterable<string, array{int|Ttl, TtlEstimate, string|null}>
     */
    public static function providerTtlCases(): iterable
    {
        yield 'shorter parent' => [20, TtlEstimate::known(60), 'local ttl 20s; composed 60s'];
        yield 'equal parent' => [60, TtlEstimate::known(60), 'local ttl 60s; composed 60s'];
        yield 'longer parent' => [120, TtlEstimate::known(60), 'local ttl 120s; composed 60s'];
        yield 'automatic' => [Ttl::Auto, TtlEstimate::known(60), null];
        yield 'zero TTL' => [0, TtlEstimate::known(60), 'local ttl 0s; composed 60s'];
        yield 'unknown dependency' => [20, TtlEstimate::unknown(), 'local ttl 20s; composed unknown'];
        yield 'unknown with upper bound' => [20, TtlEstimate::unknown(60), 'local ttl 20s; composed ≤60s'];
        yield 'invalid dependency' => [20, TtlEstimate::invalid('broken declaration'), null];
        yield 'unconstrained dependency' => [20, TtlEstimate::unconstrained(), 'local ttl 20s; composed unconstrained'];
        yield 'partial alternatives' => [300, TtlEstimate::oneOf(new TtlInterval(30, 30), new TtlInterval(600, 900)), 'local ttl 300s; composed 30/600-900s'];
        yield 'all alternatives' => [20, TtlEstimate::oneOf(new TtlInterval(30, 30), new TtlInterval(600, 900)), 'local ttl 20s; composed 30/600-900s'];
        yield 'uncertain runtime condition' => [20, TtlEstimate::unknown(60, 'runtime condition', 30, true), 'local ttl 20s; composed 30-60s'];
    }

    #[DataProvider('providerTtlCases')]
    public function testTtlIsAttributedToTheDeclarationThatWroteIt(int|Ttl $ttl, TtlEstimate $upstream, ?string $reason): void
    {
        $boundary = new BoundaryDeclaration('Page', 'execute', 'a.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, $ttl));
        $effect = (new EffectCalculator())->calculate($boundary, new DependencyConstraint($upstream, hasDependencies: true));

        self::assertSame($reason, $effect->localOverrides['ttl'] ?? null);
    }

    public function testLeafDeclarationsHaveNoBubblingToOverride(): void
    {
        $calculator = new EffectCalculator();
        $boundary = new BoundaryDeclaration('Leaf', 'execute', 'a.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 20, visibility: Visibility::Private));
        $leaf = $calculator->calculate($boundary, new DependencyConstraint());
        $constraint = $calculator->constrain([new CacheNode($boundary, $leaf)]);
        $parent = $calculator->calculate($boundary, $constraint);
        $entry = $calculator->calculate(new BoundaryDeclaration('Controller', 'show', 'a.php', 1, isCacheBoundary: false), $constraint);

        self::assertTrue($constraint->hasDependencies);
        self::assertSame([], $leaf->localOverrides);
        self::assertSame([], $calculator->calculate($boundary, new DependencyConstraint(), new StrategyEffect('S::create()', TtlEstimate::unconstrained()))->localOverrides);
        self::assertSame(['ttl' => 'local ttl 20s; composed 20s', 'visibility' => 'declared by the policy; composed private'], $parent->localOverrides);
        self::assertSame([], $entry->localOverrides);
    }

    public function testStrategyOverridePreventsFalsePolicyAttribution(): void
    {
        $boundary = new BoundaryDeclaration('Page', 'execute', 'a.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 20));
        $strategy = new StrategyEffect('S::create()', TtlEstimate::known(10), overridesExpiration: true);
        $effect = (new EffectCalculator())->calculate($boundary, new DependencyConstraint(TtlEstimate::known(60), hasDependencies: true), $strategy);

        self::assertSame(10, $effect->ttl->seconds);
        self::assertSame(['ttl' => 'overridden by the declared strategy'], $effect->localOverrides);
    }

    public function testStrategiesOverridePoliciesWithoutInventingDependencyBubbling(): void
    {
        $boundary = new BoundaryDeclaration('Page', 'execute', 'a.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 20));
        $strategy = new StrategyEffect('S::create()', TtlEstimate::known(60), overridesExpiration: true);
        $effect = (new EffectCalculator())->calculate($boundary, new DependencyConstraint(), $strategy);

        self::assertSame([], $effect->localOverrides);
    }

    public function testRuntimeTtlChoicesAreNotAttributedToThePolicy(): void
    {
        $policy = new PolicyDeclaration(PolicySource::MethodAttribute, 20);
        $dynamic = new BoundaryDeclaration('Page', 'dynamic', 'a.php', 1, $policy, hasDynamicTtl: true);
        $parameter = new BoundaryDeclaration('Page', 'parameter', 'a.php', 1, $policy, [new KeyParameter('ttl', 'int', configuration: new ParameterConfiguration(ttl: true))]);
        $constraint = new DependencyConstraint(TtlEstimate::known(60), hasDependencies: true);
        $calculator = new EffectCalculator();

        self::assertSame(['ttl' => 'overridden by #[DynamicTtl]'], $calculator->calculate($dynamic, $constraint)->localOverrides);
        self::assertSame(['ttl' => 'overridden by #[CacheTtl]'], $calculator->calculate($parameter, $constraint)->localOverrides);
    }

    public function testDescribeLocalVisibilityAndScopeRestrictSharedDependencies(): void
    {
        $calculator = new EffectCalculator();
        $constraint = new DependencyConstraint(TtlEstimate::known(60), hasDependencies: true);
        $private = new BoundaryDeclaration('Page', 'private', 'a.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, visibility: Visibility::Private));
        $scoped = new BoundaryDeclaration('Page', 'scoped', 'a.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute), [new KeyParameter('viewer', scope: Visibility::Private)]);
        $noStore = new BoundaryDeclaration('Page', 'noStore', 'a.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, visibility: Visibility::NoStore));

        self::assertSame(['visibility' => 'declared by the policy; composed shared'], $calculator->calculate($private, $constraint)->localOverrides);
        self::assertSame(['visibility' => 'declared by a scoped parameter; composed shared'], $calculator->calculate($scoped, $constraint)->localOverrides);
        self::assertSame(['visibility' => 'declared by the policy; composed shared'], $calculator->calculate($noStore, $constraint)->localOverrides);
    }

    public function testExplicitVisibilityOverridesUncertaintyButInvalidParametersRemainErrors(): void
    {
        $calculator = new EffectCalculator();
        $policy = new PolicyDeclaration(PolicySource::MethodAttribute, 20, visibility: Visibility::Private);
        $boundary = new BoundaryDeclaration('Page', 'execute', 'a.php', 1, $policy);
        $uncertain = new DependencyConstraint(TtlEstimate::known(20), visibilityUnknown: true, hasDependencies: true);
        $invalid = new BoundaryDeclaration('Page', 'invalid', 'a.php', 1, $policy, [new KeyParameter('ttl', 'string', configuration: new ParameterConfiguration(ttl: true))]);

        self::assertSame(['ttl' => 'local ttl 20s; composed 20s', 'visibility' => 'declared by the policy; composed shared'], $calculator->calculate($boundary, $uncertain)->localOverrides);
        self::assertSame([], $calculator->calculate($invalid, new DependencyConstraint(TtlEstimate::known(60), hasDependencies: true))->localOverrides);
    }
}
