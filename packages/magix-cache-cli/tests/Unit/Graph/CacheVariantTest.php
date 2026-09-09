<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheVariant;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheVariant::class)]
#[UsesNamespace('Magix\Cache')]
final class CacheVariantTest extends TestCase
{
    public function testConstraintKeepsAllMetadataFromOneCandidate(): void
    {
        $constraint = (new CacheVariant(new CacheEffect(TtlEstimate::known(20), Visibility::Private, tags: ['a']), ['A::get']))->constraint();
        self::assertSame(20, $constraint->ttl->seconds);
        self::assertSame(Visibility::Private, $constraint->visibility);
        self::assertSame(['a'], $constraint->tags);
        self::assertSame('A::get', $constraint->ttlSource);
    }

    public function testSelectStartsANewCallWithoutReusingItsCalleesDecisions(): void
    {
        $base = new CacheVariant(new CacheEffect(TtlEstimate::known(20)));
        $first = $base->select('child', 0)->select('caller', 1, replace: true);
        self::assertSame(['caller' => 1], $first->selections);
        self::assertSame($base->effect, $first->effect);
    }

    public function testCompatibleRejectsTwoAlternativesOfTheSameValue(): void
    {
        $base = new CacheVariant(new CacheEffect(TtlEstimate::known(20)));
        self::assertFalse($base->select('value', 0)->compatible($base->select('value', 1)));
        self::assertTrue($base->select('first', 0)->compatible($base->select('second', 1)));
    }

    public function testEqualsDoesNotCollapseDifferentDependenciesWithEqualMetadata(): void
    {
        $first = new CacheVariant(new CacheEffect(TtlEstimate::known(20)), ['A']);
        self::assertTrue($first->equals(new CacheVariant(new CacheEffect(TtlEstimate::known(20)), ['A'])));
        self::assertFalse($first->equals(new CacheVariant(new CacheEffect(TtlEstimate::known(20)), ['B'])));
    }
}
