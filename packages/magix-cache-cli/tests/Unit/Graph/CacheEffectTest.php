<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheEffect::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
final class CacheEffectTest extends TestCase
{
    public function testEffectKeepsTheReasonsBehindEveryValue(): void
    {
        $estimate = TtlEstimate::known(20, 'declared 120s, capped by ProductQuery::execute');
        $effect = new CacheEffect(
            ttl: $estimate,
            visibility: Visibility::Private,
            storable: true,
            tags: ['product'],
            visibilityReason: 'restricted by ViewerQuery::execute',
            problems: ['none'],
        );

        self::assertSame($estimate, $effect->ttl);
        self::assertSame(Visibility::Private, $effect->visibility);
        self::assertTrue($effect->storable);
        self::assertSame(['product'], $effect->tags);
        self::assertSame('restricted by ViewerQuery::execute', $effect->visibilityReason);
        self::assertSame(['none'], $effect->problems);
    }

    public function testEffectDefaultsToAnUnknownUnstorableSharedResult(): void
    {
        $effect = new CacheEffect();

        self::assertSame(TtlEstimateState::Unknown, $effect->ttl->state);
        self::assertSame(Visibility::Shared, $effect->visibility);
        self::assertFalse($effect->storable);
    }

    public function testVisibilityLabelDistinguishesAProvenFloorFromAnExactValue(): void
    {
        $effect = new CacheEffect(visibility: Visibility::Private, visibilityUnknown: true);

        self::assertSame('private or stricter', $effect->visibilityLabel());
        self::assertSame('shared', (new CacheEffect())->visibilityLabel());
    }

    public function testTagsLabelKeepsKnownAndRuntimeTagsSeparate(): void
    {
        self::assertSame('fixed + runtime tags', (new CacheEffect(tags: ['fixed'], tagsUnknown: true))->tagsLabel());
        self::assertSame('runtime tags', (new CacheEffect(tagsUnknown: true))->tagsLabel());
        self::assertSame('-', (new CacheEffect())->tagsLabel());
    }
}
