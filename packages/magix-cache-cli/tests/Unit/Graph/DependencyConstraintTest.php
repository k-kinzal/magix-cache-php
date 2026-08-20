<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyConstraint::class)]
final class DependencyConstraintTest extends TestCase
{
    public function testConstraintNamesTheDependencyItComesFrom(): void
    {
        $constraint = new DependencyConstraint(20, 'ProductQuery::execute', Visibility::Private, 'ViewerQuery::execute', ['product']);

        self::assertSame(20, $constraint->ttl);
        self::assertSame('ProductQuery::execute', $constraint->ttlSource);
        self::assertSame(Visibility::Private, $constraint->visibility);
        self::assertSame('ViewerQuery::execute', $constraint->visibilitySource);
        self::assertSame(['product'], $constraint->tags);
    }

    public function testConstraintDefaultsToNoRestrictionAtAll(): void
    {
        $constraint = new DependencyConstraint();

        self::assertNull($constraint->ttl);
        self::assertSame(Visibility::Shared, $constraint->visibility);
        self::assertSame([], $constraint->tags);
    }
}
