<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\CachePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cache::class)]
#[UsesClass(CachePolicy::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
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
}
