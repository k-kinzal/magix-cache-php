<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use InvalidArgumentException;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\CachePolicy;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cache::class)]
#[UsesClass(CachePolicy::class)]
final class CacheTest extends TestCase
{
    public function testPolicyPreservesConfiguration(): void
    {
        $attribute = new Cache(ttl: 30, tags: ['product:1'], version: '2');
        $policy = $attribute->policy();

        self::assertSame(30, $policy->ttl);
        self::assertSame(['product:1'], $policy->tags);
        self::assertSame('2', $policy->version);
    }

    public function testPolicyRequiresMaximumForUpstreamMode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cache(ttl: Ttl::FromUpstream);
    }
}
