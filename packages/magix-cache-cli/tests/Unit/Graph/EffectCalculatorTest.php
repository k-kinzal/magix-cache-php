<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Cli\Graph\EffectCalculator;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectCalculator::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(DependencyConstraint::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(PolicyDeclaration::class)]
final class EffectCalculatorTest extends TestCase
{
    public function testConstrainSelectsTheEarliestExpirationAndStrictestVisibility(): void
    {
        $constraint = (new EffectCalculator())->constrain([
            new CacheNode(
                new BoundaryDeclaration('App\InventoryQuery', 'execute', 'a.php', 1),
                new CacheEffect(ttl: 60, tags: ['inventory']),
            ),
            new CacheNode(
                new BoundaryDeclaration('App\ViewerQuery', 'execute', 'b.php', 1),
                new CacheEffect(ttl: 30, visibility: Visibility::Private, tags: ['viewer']),
            ),
        ]);

        self::assertSame(30, $constraint->ttl);
        self::assertSame('ViewerQuery::execute', $constraint->ttlSource);
        self::assertSame(Visibility::Private, $constraint->visibility);
        self::assertSame('ViewerQuery::execute', $constraint->visibilitySource);
        self::assertSame(['inventory', 'viewer'], $constraint->tags);
    }

    public function testCalculateClampsAFixedTtlToItsDependencies(): void
    {
        $boundary = new BoundaryDeclaration(
            class: 'App\PageQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, 120, tags: ['page']),
        );

        $effect = (new EffectCalculator())->calculate(
            $boundary,
            new DependencyConstraint(20, 'ProductQuery::execute', tags: ['product']),
        );

        self::assertSame(20, $effect->ttl);
        self::assertSame('declared 120s, clamped by ProductQuery::execute', $effect->ttlReason);
        self::assertSame(['page', 'product'], $effect->tags);
        self::assertTrue($effect->storable);
    }

    public function testCalculateReportsABoundaryWithoutAnyPolicy(): void
    {
        $boundary = new BoundaryDeclaration('App\PageQuery', 'execute', 'a.php', 1);

        $effect = (new EffectCalculator())->calculate($boundary, new DependencyConstraint());

        self::assertNull($effect->ttl);
        self::assertFalse($effect->storable);
        self::assertCount(1, $effect->problems);
    }

    public function testCalculateRestrictsVisibilityThroughScopedParameters(): void
    {
        $boundary = new BoundaryDeclaration(
            class: 'App\ViewerQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, 30),
            parameters: [new KeyParameter('viewerId', scope: Visibility::Private)],
        );

        $effect = (new EffectCalculator())->calculate($boundary, new DependencyConstraint());

        self::assertSame(Visibility::Private, $effect->visibility);
        self::assertSame('restricted by a scoped parameter', $effect->visibilityReason);
    }

    public function testLifetimeInheritsAndCapsUpstreamExpirations(): void
    {
        $calculator = new EffectCalculator();
        $boundary = new BoundaryDeclaration('App\PageQuery', 'execute', 'a.php', 1);
        $constraint = new DependencyConstraint(45, 'FeedQuery::execute');

        $automatic = $calculator->lifetime($boundary, new PolicyDeclaration(PolicySource::MethodAttribute, Ttl::Auto), $constraint);
        $upstream = $calculator->lifetime(
            $boundary,
            new PolicyDeclaration(PolicySource::MethodAttribute, Ttl::FromUpstream, maxTtl: 10),
            $constraint,
        );

        self::assertSame(45, $automatic->ttl);
        self::assertSame('inherited from FeedQuery::execute', $automatic->ttlReason);
        self::assertSame(10, $upstream->ttl);
    }

    public function testLifetimeReportsAnInheritedTtlWithoutAnySource(): void
    {
        $calculator = new EffectCalculator();
        $boundary = new BoundaryDeclaration('App\PageQuery', 'execute', 'a.php', 1);

        $missing = $calculator->lifetime(
            $boundary,
            new PolicyDeclaration(PolicySource::MethodAttribute, Ttl::Auto),
            new DependencyConstraint(),
        );
        $unreadable = $calculator->lifetime(
            $boundary,
            new PolicyDeclaration(PolicySource::Unresolved, null),
            new DependencyConstraint(),
        );

        self::assertCount(1, $missing->problems);
        self::assertCount(1, $unreadable->problems);
    }

    public function testLifetimeAcceptsExpirationsSuppliedByTheBoundaryItself(): void
    {
        $boundary = new BoundaryDeclaration(
            class: 'App\UpstreamQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            suppliesMetadata: true,
        );

        $effect = (new EffectCalculator())->lifetime(
            $boundary,
            new PolicyDeclaration(PolicySource::MethodAttribute, Ttl::Auto),
            new DependencyConstraint(),
        );

        self::assertSame([], $effect->problems);
        self::assertSame('supplied at runtime by the boundary itself', $effect->ttlReason);
    }

    public function testTagsAreDeduplicatedAndSorted(): void
    {
        self::assertSame(['a', 'b'], (new EffectCalculator())->tags(['b', 'a', 'b']));
    }
}
