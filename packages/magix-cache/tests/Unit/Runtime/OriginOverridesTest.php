<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Extension\DynamicTtlContext;
use Magix\Cache\Runtime\OriginOverrides;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\FixedTtlResolver;

#[CoversClass(OriginOverrides::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CachePolicy::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(DynamicTtlContext::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesClass(\Magix\Cache\Runtime\Policy\PolicySemantics::class)]
final class OriginOverridesTest extends TestCase
{
    public function testApplyOverridesAnUpstreamExpiration(): void
    {
        $result = Cached::of('value', new CacheMetadata(expiresAt: 105.0));

        $metadata = (new OriginOverrides())->apply(new CachePolicy(ttl: 20), null, $result, 'key', 100.0);

        self::assertSame(120.0, $metadata->expiresAt);
    }

    public function testDynamicLifetimeSuppliesAnAutomaticBoundary(): void
    {
        $result = Cached::of('value');

        $metadata = (new OriginOverrides())->apply(
            new CachePolicy(ttl: Ttl::Auto),
            new FixedTtlResolver(5),
            $result,
            'key',
            100.0,
        );

        self::assertSame(105.0, $metadata->expiresAt);
    }

    public function testDynamicLifetimeOverridesThePolicy(): void
    {
        $result = Cached::of('value', new CacheMetadata(expiresAt: 112.0));

        $metadata = (new OriginOverrides())->apply(
            new CachePolicy(ttl: 20),
            new FixedTtlResolver(5),
            $result,
            'key',
            100.0,
        );

        self::assertSame(105.0, $metadata->expiresAt);
    }

    public function testDynamicLifetimeOverridesParametersAtOneBaseTime(): void
    {
        $metadata = (new OriginOverrides())->apply(
            new CachePolicy(ttl: 60),
            new FixedTtlResolver(20),
            Cached::of('value', new CacheMetadata(expiresAt: 150.0)),
            'key',
            100.0,
            parameterTtl: 5,
        );

        self::assertSame(120.0, $metadata->expiresAt);
    }
}
