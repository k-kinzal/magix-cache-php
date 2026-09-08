<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyConstraint::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
final class DependencyConstraintTest extends TestCase
{
    public function testConstraintNamesTheDependencyItComesFrom(): void
    {
        $estimate = TtlEstimate::known(20);
        $constraint = new DependencyConstraint($estimate, 'ProductQuery::execute', Visibility::Private, 'ViewerQuery::execute', ['product']);

        self::assertSame($estimate, $constraint->ttl);
        self::assertSame('ProductQuery::execute', $constraint->ttlSource);
        self::assertSame(Visibility::Private, $constraint->visibility);
        self::assertSame('ViewerQuery::execute', $constraint->visibilitySource);
        self::assertSame(['product'], $constraint->tags);
    }

    public function testConstraintDefaultsToNoRestrictionAtAll(): void
    {
        $constraint = new DependencyConstraint();

        self::assertSame(TtlEstimateState::Unconstrained, $constraint->ttl->state);
        self::assertSame(Visibility::Shared, $constraint->visibility);
        self::assertSame([], $constraint->tags);
    }
}
