<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Metadata;

use LogicException;
use Magix\Cache\CachePolicy;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheMetadata::class)]
#[UsesClass(CachePolicy::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\ConstraintMeet::class)]
#[UsesClass(Visibility::class)]
final class CacheMetadataTest extends TestCase
{
    public function testTopIsTheMergeIdentity(): void
    {
        $metadata = CacheMetadata::forTtl(20, 100.0, ['product:1']);

        self::assertEquals($metadata, CacheMetadata::top()->merge($metadata));
    }

    public function testForTtlStoresAbsoluteExpiration(): void
    {
        self::assertSame(120.0, CacheMetadata::forTtl(20, 100.0)->expiresAt);
    }

    public function testUncacheableForbidsStorage(): void
    {
        $metadata = CacheMetadata::uncacheable('upstream failure');

        self::assertFalse($metadata->cacheable);
        self::assertSame(Visibility::NoStore, $metadata->visibility);
        self::assertSame(['upstream failure'], $metadata->reasons);
    }

    public function testMergeUsesMeetSemantics(): void
    {
        $left = new CacheMetadata(120.0, tags: ['a']);
        $right = new CacheMetadata(110.0, tags: ['b'], visibility: Visibility::Private);
        $merged = $left->merge($right);

        self::assertSame(110.0, $merged->expiresAt);
        self::assertSame(['a', 'b'], $merged->tags);
        self::assertSame(Visibility::Private, $merged->visibility);
    }

    public function testApplyPolicyClampsFixedExpirationAndComposesConstraints(): void
    {
        $metadata = new CacheMetadata(
            expiresAt: 105.0,
            tags: ['dependency'],
            visibility: Visibility::Private,
        );

        $applied = $metadata->applyPolicy(new CachePolicy(
            ttl: 20,
            tags: ['boundary'],
            visibility: Visibility::Shared,
        ), 100.0);

        self::assertSame(105.0, $applied->expiresAt);
        self::assertSame(['boundary', 'dependency'], $applied->tags);
        self::assertSame(Visibility::Private, $applied->visibility);
    }

    public function testApplyPolicyCanReplaceDependencyExpirationWhenClampIsDisabled(): void
    {
        $metadata = new CacheMetadata(expiresAt: 105.0);

        $applied = $metadata->applyPolicy(new CachePolicy(ttl: 20, clamp: false), 100.0);

        self::assertSame(120.0, $applied->expiresAt);
    }

    public function testApplyPolicyClampsUpstreamExpirationToMaximumTtl(): void
    {
        $metadata = new CacheMetadata(expiresAt: 120.0);

        $applied = $metadata->applyPolicy(
            new CachePolicy(ttl: Ttl::FromUpstream, maxTtl: 10),
            100.0,
        );

        self::assertSame(110.0, $applied->expiresAt);
    }

    public function testApplyPolicyRequiresFiniteExpirationForAutomaticTtl(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Auto requires a finite dependency or upstream expiration.');

        CacheMetadata::top()->applyPolicy(new CachePolicy(ttl: Ttl::Auto), 100.0);
    }

    public function testWithExpiresAtPreservesOtherConstraints(): void
    {
        $metadata = (new CacheMetadata(tags: ['a']))->withExpiresAt(150.0);

        self::assertSame(150.0, $metadata->expiresAt);
        self::assertSame(['a'], $metadata->tags);
    }

    public function testWithTagsFormsASetUnion(): void
    {
        $metadata = (new CacheMetadata(tags: ['b']))->withTags(['a', 'b']);

        self::assertSame(['a', 'b'], $metadata->tags);
    }

    public function testWithVisibilityOnlyBecomesMoreRestrictive(): void
    {
        $metadata = (new CacheMetadata(visibility: Visibility::Private))->withVisibility(Visibility::Shared);

        self::assertSame(Visibility::Private, $metadata->visibility);
    }

    public function testIsStorableRequiresFiniteFutureExpiration(): void
    {
        self::assertTrue((new CacheMetadata(expiresAt: 101.0))->isStorable(100.0));
        self::assertFalse(CacheMetadata::top()->isStorable(100.0));
        self::assertFalse((new CacheMetadata(expiresAt: 99.0))->isStorable(100.0));
    }

}
