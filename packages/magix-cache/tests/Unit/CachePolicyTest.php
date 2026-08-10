<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Magix\Cache\CachePolicy;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CachePolicy::class)]
#[UsesClass(Visibility::class)]
final class CachePolicyTest extends TestCase
{
    public function testExplicitConfigurationIsPreserved(): void
    {
        $policy = new CachePolicy(ttl: 30, tags: ['product:1'], version: '2');

        self::assertSame(30, $policy->ttl);
        self::assertSame(['product:1'], $policy->tags);
        self::assertSame('2', $policy->version);
    }

    public function testUpstreamModeRequiresMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CachePolicy(ttl: Ttl::FromUpstream);
    }

    public function testRestrictVisibilityOnlyMakesPolicyStricter(): void
    {
        $policy = new CachePolicy(ttl: 30, tags: ['product:1']);
        $private = $policy->restrictVisibility(Visibility::Private);

        self::assertNotSame($policy, $private);
        self::assertSame(Visibility::Private, $private->visibility);
        self::assertSame(30, $private->ttl);
        self::assertSame(['product:1'], $private->tags);
        self::assertSame($private, $private->restrictVisibility(Visibility::Shared));
    }
}
