<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheEffect::class)]
final class CacheEffectTest extends TestCase
{
    public function testEffectKeepsTheReasonsBehindEveryValue(): void
    {
        $effect = new CacheEffect(
            ttl: 20,
            visibility: Visibility::Private,
            storable: true,
            tags: ['product'],
            ttlReason: 'clamped by ProductQuery::execute',
            visibilityReason: 'restricted by ViewerQuery::execute',
            problems: ['none'],
        );

        self::assertSame(20, $effect->ttl);
        self::assertSame(Visibility::Private, $effect->visibility);
        self::assertTrue($effect->storable);
        self::assertSame(['product'], $effect->tags);
        self::assertSame('clamped by ProductQuery::execute', $effect->ttlReason);
        self::assertSame('restricted by ViewerQuery::execute', $effect->visibilityReason);
        self::assertSame(['none'], $effect->problems);
    }

    public function testEffectDefaultsToAnUnstorableSharedResult(): void
    {
        $effect = new CacheEffect();

        self::assertNull($effect->ttl);
        self::assertSame(Visibility::Shared, $effect->visibility);
        self::assertFalse($effect->storable);
    }
}
