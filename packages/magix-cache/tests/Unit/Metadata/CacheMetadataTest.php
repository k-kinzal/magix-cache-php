<?php

declare(strict_types=1);

namespace Tests\Unit\Metadata;

use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\CacheTokenSet;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheMetadata::class)]
#[UsesClass(CacheTokenSet::class)]
#[UsesClass(Visibility::class)]
final class CacheMetadataTest extends TestCase
{
    /**
     * @return array<string, array{CacheMetadata}>
     */
    public static function providerSamples(): array
    {
        return [
            'top' => [CacheMetadata::top()],
            'tagged expiration' => [new CacheMetadata(expiresAt: 120.0, tags: ['b', 'a', 'b'])],
            'expired private' => [new CacheMetadata(expiresAt: 90.0, visibility: Visibility::Private)],
            'uncacheable reasons' => [new CacheMetadata(cacheable: false, reasons: ['second', 'first', 'second'])],
            'no-store' => [CacheMetadata::uncacheable('origin opted out')],
        ];
    }

    /**
     * @return iterable<string, array{CacheMetadata, CacheMetadata}>
     */
    public static function providerSamplePairs(): iterable
    {
        foreach (self::providerSamples() as $leftName => $left) {
            foreach (self::providerSamples() as $rightName => $right) {
                yield $leftName.' + '.$rightName => [$left[0], $right[0]];
            }
        }
    }

    /**
     * @return iterable<string, array{CacheMetadata, CacheMetadata, CacheMetadata}>
     */
    public static function providerSampleTriples(): iterable
    {
        foreach (self::providerSamples() as $firstName => $first) {
            foreach (self::providerSamplePairs() as $pairName => $pair) {
                yield $firstName.' + '.$pairName => [$first[0], $pair[0], $pair[1]];
            }
        }
    }

    #[DataProvider('providerSamples')]
    public function testTopIsTheMeetIdentity(CacheMetadata $metadata): void
    {
        self::assertTrue($metadata->equals(CacheMetadata::top()->meet($metadata)));
        self::assertTrue($metadata->equals($metadata->meet(CacheMetadata::top())));
    }

    #[DataProvider('providerSamplePairs')]
    public function testMeetIsCommutative(CacheMetadata $left, CacheMetadata $right): void
    {
        self::assertTrue($left->meet($right)->equals($right->meet($left)));
    }

    #[DataProvider('providerSampleTriples')]
    public function testMeetIsAssociative(CacheMetadata $first, CacheMetadata $second, CacheMetadata $third): void
    {
        self::assertTrue(
            $first->meet($second)->meet($third)->equals($first->meet($second->meet($third))),
        );
    }

    #[DataProvider('providerSamples')]
    public function testMeetIsIdempotent(CacheMetadata $metadata): void
    {
        self::assertTrue($metadata->equals($metadata->meet($metadata)));
    }

    #[DataProvider('providerSamplePairs')]
    public function testMeetResultIsAsStrictAsEveryInput(CacheMetadata $left, CacheMetadata $right): void
    {
        $met = $left->meet($right);

        self::assertTrue($met->equals($met->meet($left)));
        self::assertTrue($met->equals($met->meet($right)));
    }

    public function testMeetSelectsTheStricterConstraints(): void
    {
        $left = new CacheMetadata(120.0, tags: ['a']);
        $right = new CacheMetadata(110.0, tags: ['b'], visibility: Visibility::Private);
        $met = $left->meet($right);

        self::assertSame(110.0, $met->expiresAt);
        self::assertSame(['a', 'b'], $met->tags);
        self::assertSame(Visibility::Private, $met->visibility);
    }

    public function testEqualsComparesValuesNotObjectIdentity(): void
    {
        $left = new CacheMetadata(expiresAt: 120.0, tags: ['b', 'a']);
        $right = new CacheMetadata(expiresAt: 120.0, tags: ['a', 'b', 'a']);

        self::assertTrue($left->equals($right));
        self::assertFalse($left->equals(new CacheMetadata(expiresAt: 120.0, tags: ['a'])));
    }

    public function testForTtlStoresAbsoluteExpiration(): void
    {
        self::assertSame(120.0, CacheMetadata::forTtl(20, 100.0)->expiresAt);
    }

    public function testUncacheableForbidsStorage(): void
    {
        $metadata = CacheMetadata::uncacheable('upstream failure');

        self::assertFalse($metadata->cacheable);
        self::assertSame(Visibility::NoStore, $metadata->visibility);
        self::assertSame(['upstream failure'], $metadata->reasons);
    }

    public function testTopDeclaresNoConstraints(): void
    {
        self::assertNull(CacheMetadata::top()->expiresAt);
        self::assertTrue(CacheMetadata::top()->cacheable);
    }

    public function testIsStorableRequiresFiniteFutureExpiration(): void
    {
        self::assertTrue((new CacheMetadata(expiresAt: 101.0))->isStorable(100.0));
        self::assertFalse(CacheMetadata::top()->isStorable(100.0));
        self::assertFalse((new CacheMetadata(expiresAt: 99.0))->isStorable(100.0));
        self::assertFalse((new CacheMetadata(expiresAt: 101.0, cacheable: false))->isStorable(100.0));
        self::assertFalse((new CacheMetadata(expiresAt: 101.0, visibility: Visibility::NoStore))->isStorable(100.0));
    }
    public function testWithExpirationCanExtendOrClearOnlyTheExpiration(): void
    {
        $original = new CacheMetadata(expiresAt: 120.0, tags: ['source'], visibility: Visibility::Private);
        self::assertEquals(new CacheMetadata(expiresAt: 160.0, tags: ['source'], visibility: Visibility::Private), $original->withExpiration(160.0));
        self::assertEquals(new CacheMetadata(tags: ['source'], visibility: Visibility::Private), $original->withExpiration(null));
        self::assertSame(120.0, $original->expiresAt);
    }

    public function testWithCacheabilityCanExplicitlyPermitStorage(): void
    {
        $original = new CacheMetadata(expiresAt: 160.0, cacheable: false, reasons: ['source']);
        self::assertTrue($original->withCacheability(true)->isStorable(100.0));
        self::assertSame(['source'], $original->withCacheability(true)->reasons);
        self::assertFalse($original->cacheable);
    }

    public function testWithTagsReplacesAndClearsWithoutChangingExpiration(): void
    {
        $original = new CacheMetadata(expiresAt: 160.0, tags: ['source']);
        self::assertSame(['new'], $original->withTags(['new', 'new'])->tags);
        self::assertSame([], $original->withTags([])->tags);
        self::assertSame(160.0, $original->withTags([])->expiresAt);
        self::assertSame(['source'], $original->tags);
    }

    public function testWithVisibilityCanExplicitlyShareAPrivateResult(): void
    {
        $original = new CacheMetadata(expiresAt: 160.0, visibility: Visibility::Private);
        self::assertSame(Visibility::Shared, $original->withVisibility(Visibility::Shared)->visibility);
        self::assertSame(160.0, $original->withVisibility(Visibility::Shared)->expiresAt);
        self::assertSame(Visibility::Private, $original->visibility);
    }

    public function testWithReasonsReplacesOnlyDiagnostics(): void
    {
        $original = new CacheMetadata(cacheable: false, reasons: ['source']);
        self::assertSame([], $original->withReasons([])->reasons);
        self::assertSame(['new'], $original->withReasons(['new'])->reasons);
        self::assertFalse($original->withReasons([])->cacheable);
    }
}
