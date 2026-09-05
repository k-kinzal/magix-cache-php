<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Extension;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Extension\DynamicTtlContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DynamicTtlContext::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
final class DynamicTtlContextTest extends TestCase
{
    public function testContextExposesTheSuccessfulOriginResult(): void
    {
        $result = Cached::of('value');
        $context = new DynamicTtlContext('key', $result, 100.0);

        self::assertSame('key', $context->key);
        self::assertSame($result, $context->result);
        self::assertSame(100.0, $context->now);
    }
}
