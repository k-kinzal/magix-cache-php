<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph\Analysis;

use Magix\Cache\Cli\Graph\Analysis\TtlReference;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheVariant;
use Magix\Cache\Cli\Graph\TtlEstimate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(TtlReference::class)]
#[UsesNamespace('Magix\Cache')]
final class TtlReferenceTest extends TestCase
{
    public function testFromVariantsRejectsAnArbitraryChoiceBetweenConflictingInputs(): void
    {
        $ten = new CacheVariant(new CacheEffect(TtlEstimate::known(10)), ['A']);
        $twenty = new CacheVariant(new CacheEffect(TtlEstimate::known(20)), ['B']);
        self::assertNull(TtlReference::fromVariants([$ten, $twenty, $ten]));
        self::assertNull(TtlReference::fromVariants([new CacheVariant(new CacheEffect())]));
        $reference = TtlReference::fromVariants([$ten]);
        self::assertNotNull($reference);
        self::assertSame(10, $reference->seconds);
        self::assertSame(['A'], $reference->sources);
    }

    public function testJsonSerializePreservesTheBasisForAnUnprovenNumber(): void
    {
        $effect = ReportSource::node('Page::automatic')->effect;
        $reference = $effect->analysis->ttlReference;
        self::assertNotNull($reference);
        self::assertSame(10, $reference->jsonSerialize()['seconds']);
        self::assertSame(['Leaf::get'], $reference->sources);
        self::assertNull($effect->ttl->upperBound);
        self::assertNull($effect->ttl->seconds);
        self::assertFalse($effect->ttl->hasFiniteExpiration());
    }
}
