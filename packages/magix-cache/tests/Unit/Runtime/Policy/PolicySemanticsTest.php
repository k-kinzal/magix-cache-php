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
    public function testConstraintOfAFixedLifetimeNeverExtendsTheUpstreamExpiration(): void
    {
        $upstream = new CacheMetadata(expiresAt: 105.0);
        $constraint = (new PolicySemantics())->constraint(new CachePolicy(ttl: 20), 105.0, 100.0);

        self::assertSame(120.0, $constraint->expiresAt);
        self::assertSame(105.0, $upstream->meet($constraint)->expiresAt);
    }

    public function testConstraintOfAFixedLifetimeStandsAloneWithoutUpstream(): void
    {
        $constraint = (new PolicySemantics())->constraint(new CachePolicy(ttl: 20), null, 100.0);

        self::assertSame(120.0, $constraint->expiresAt);
    }

    public function testConstraintCarriesTheDeclaredTagsAndVisibility(): void
    {
        $constraint = (new PolicySemantics())->constraint(
            new CachePolicy(ttl: 20, tags: ['boundary'], visibility: Visibility::Private),
            null,
            100.0,
        );

        self::assertSame(['boundary'], $constraint->tags);
        self::assertSame(Visibility::Private, $constraint->visibility);
    }

    public function testConstraintOfAutoInheritsTheFiniteUpstreamExpiration(): void
    {
        $upstream = new CacheMetadata(expiresAt: 110.0);
        $constraint = (new PolicySemantics())->constraint(new CachePolicy(ttl: Ttl::Auto), 110.0, 100.0);

        self::assertNull($constraint->expiresAt);
        self::assertSame(110.0, $upstream->meet($constraint)->expiresAt);
    }

    public function testConstraintOfFromUpstreamCapsTheUpstreamExpiration(): void
    {
        $upstream = new CacheMetadata(expiresAt: 120.0);
        $constraint = (new PolicySemantics())->constraint(
            new CachePolicy(ttl: Ttl::FromUpstream, maxTtl: 10),
            120.0,
            100.0,
        );

        self::assertSame(110.0, $upstream->meet($constraint)->expiresAt);
    }
}
