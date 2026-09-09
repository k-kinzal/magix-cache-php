<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\AlternativeEffects;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheVariant;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlternativeEffects::class)]
#[UsesNamespace('Magix\Cache')]
final class AlternativeEffectsTest extends TestCase
{
    public function testSummarizeKeepsOnlySharedFactsAndUnionsLifetimes(): void
    {
        $effect = (new AlternativeEffects())->summarize([
            new CacheVariant(new CacheEffect(TtlEstimate::known(20), Visibility::Shared, true, ['a', 'common'])),
            new CacheVariant(new CacheEffect(TtlEstimate::known(60), Visibility::Private, true, ['b', 'common'])),
        ]);
        self::assertSame('20/60s', $effect->ttl->label());
        self::assertTrue($effect->ttl->hasFiniteExpiration());
        self::assertSame(['common'], $effect->tags);
        self::assertTrue($effect->tagsUnknown);
        self::assertSame(Visibility::Shared, $effect->visibility);
        self::assertTrue($effect->visibilityUnknown);
        self::assertTrue($effect->storable);
    }

    public function testTtlNeverConvertsAnUnconstrainedAlternativeIntoAFiniteDeadline(): void
    {
        $ttl = (new AlternativeEffects())->ttl(TtlEstimate::known(20), TtlEstimate::unconstrained());
        self::assertFalse($ttl->hasFiniteExpiration());
        self::assertNull($ttl->upperBound);
    }
}
