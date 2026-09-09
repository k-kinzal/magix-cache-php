<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Policy;

use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\PolicySemantics;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PolicySemantics::class)]
#[UsesClass(CachePolicy::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(Visibility::class)]
final class PolicySemanticsTest extends TestCase
{
    public function testApplyFixedLifetimeOverridesUpstreamAndPreservesOtherFields(): void
    {
        $upstream = new CacheMetadata(expiresAt: 105.0, cacheable: false, tags: ['dependency'], visibility: Visibility::Private, reasons: ['source']);
        $result = (new PolicySemantics())->apply(new CachePolicy(ttl: 60), $upstream, 100.0);

        self::assertEquals($upstream->withExpiration(160.0), $result);
        self::assertSame(105.0, $upstream->expiresAt);
    }

    public function testApplyUnspecifiedFieldsInheritAndExplicitEmptyOrSharedFieldsReplace(): void
    {
        $upstream = new CacheMetadata(expiresAt: 105.0, tags: ['dependency'], visibility: Visibility::Private);
        $semantics = new PolicySemantics();

        self::assertEquals($upstream, $semantics->apply(new CachePolicy(), $upstream, 100.0));
        $result = $semantics->apply(new CachePolicy(tags: [], visibility: Visibility::Shared), $upstream, 100.0);
        self::assertSame([], $result->tags);
        self::assertSame(Visibility::Shared, $result->visibility);
        self::assertSame(105.0, $result->expiresAt);
        $semantics->validate(new CachePolicy(), $result);
    }

    public function testApplyFromUpstreamExplicitlyCapsWithoutExtending(): void
    {
        $semantics = new PolicySemantics();
        $policy = new CachePolicy(ttl: Ttl::FromUpstream, maxTtl: 10);
        self::assertSame(110.0, $semantics->apply($policy, new CacheMetadata(expiresAt: 120.0), 100.0)->expiresAt);
        self::assertSame(105.0, $semantics->apply($policy, new CacheMetadata(expiresAt: 105.0), 100.0)->expiresAt);
    }

    public function testApplyFixedLifetimeCanExplicitlyRefreshAnExpiredDependency(): void
    {
        $result = (new PolicySemantics())->apply(new CachePolicy(ttl: 60), new CacheMetadata(expiresAt: 90.0), 100.0);

        self::assertSame(160.0, $result->expiresAt);
    }
    public function testValidateAcceptsAnExpirationSuppliedByALaterOverride(): void
    {
        $metadata = CacheMetadata::top()->withExpiration(160.0);
        (new PolicySemantics())->validate(new CachePolicy(), $metadata);
        self::assertSame(160.0, $metadata->expiresAt);
    }
}
