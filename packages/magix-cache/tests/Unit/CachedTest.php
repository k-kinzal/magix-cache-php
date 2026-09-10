<?php

declare(strict_types=1);

namespace Tests\Unit;

use function array_diff;

use Generator;
use Magix\Cache\Cached;
use Magix\Cache\Composition\Capability10;
use Magix\Cache\Composition\Capability2;
use Magix\Cache\Composition\Capability3;
use Magix\Cache\Composition\Capability4;
use Magix\Cache\Composition\Capability5;
use Magix\Cache\Composition\Capability6;
use Magix\Cache\Composition\Capability7;
use Magix\Cache\Composition\Capability8;
use Magix\Cache\Composition\Capability9;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\KeyDto;

#[CoversClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(Visibility::class)]
#[UsesClass(Capability2::class)]
#[UsesClass(Capability3::class)]
#[UsesClass(Capability4::class)]
#[UsesClass(Capability5::class)]
#[UsesClass(Capability6::class)]
#[UsesClass(Capability7::class)]
#[UsesClass(Capability8::class)]
#[UsesClass(Capability9::class)]
#[UsesClass(Capability10::class)]
final class CachedTest extends TestCase
{
    public function testOfUsesTopMetadataByDefault(): void
    {
        $result = Cached::of('value');

        self::assertSame('value', $result->value());
        self::assertTrue(CacheMetadata::top()->equals($result->metadata));
    }

    public function testValueReturnsWrappedValue(): void
    {
        $value = new KeyDto(1);

        self::assertSame($value, Cached::of($value)->value());
    }

    public function testMapTransformsTheValueAndKeepsTheMetadata(): void
    {
        $metadata = new CacheMetadata(expiresAt: 120.0, tags: ['product:1']);

        $mapped = Cached::of(2, $metadata)->map(static fn (int $value): int => $value * 10);

        self::assertSame(20, $mapped->value());
        self::assertTrue($metadata->equals($mapped->metadata));
    }

    public function testFlatMapComposesTheMetadataOfBothResults(): void
    {
        $first = Cached::of(1, new CacheMetadata(expiresAt: 120.0, tags: ['a']));

        $chained = $first->flatMap(
            static fn (int $value): Cached => Cached::of(
                $value + 1,
                new CacheMetadata(expiresAt: 110.0, tags: ['b'], visibility: Visibility::Private),
            ),
        );

        self::assertSame(2, $chained->value());
        self::assertSame(110.0, $chained->metadata->expiresAt);
        self::assertSame(['a', 'b'], $chained->metadata->tags);
        self::assertSame(Visibility::Private, $chained->metadata->visibility);
    }

    public function testFlatMapSatisfiesTheLeftAndRightIdentityLaws(): void
    {
        $next = static fn (int $value): Cached => Cached::of(
            $value * 2,
            new CacheMetadata(expiresAt: 110.0, tags: ['dependency']),
        );
        $bound = Cached::of(3, new CacheMetadata(expiresAt: 120.0));

        $leftIdentity = Cached::of(3)->flatMap($next);
        $rightIdentity = $bound->flatMap(static fn (int $value): Cached => Cached::of($value));

        self::assertSame($next(3)->value(), $leftIdentity->value());
        self::assertTrue($next(3)->metadata->equals($leftIdentity->metadata));
        self::assertSame($bound->value(), $rightIdentity->value());
        self::assertTrue($bound->metadata->equals($rightIdentity->metadata));
    }

    public function testFlatMapSatisfiesTheAssociativityLaw(): void
    {
        $first = static fn (int $value): Cached => Cached::of(
            $value + 1,
            new CacheMetadata(expiresAt: 110.0, tags: ['first']),
        );
        $second = static fn (int $value): Cached => Cached::of(
            $value * 2,
            new CacheMetadata(expiresAt: 105.0, tags: ['second']),
        );
        $source = Cached::of(1, new CacheMetadata(expiresAt: 120.0));

        $sequential = $source->flatMap($first)->flatMap($second);
        $nested = $source->flatMap(static fn (int $value): Cached => $first($value)->flatMap($second));

        self::assertSame($sequential->value(), $nested->value());
        self::assertTrue($sequential->metadata->equals($nested->metadata));
    }

    public function testCombine2CreatesCapability2(): void
    {
        $result = Cached::of(1, new CacheMetadata(expiresAt: 120.0))
            ->combine2(Cached::of('two', new CacheMetadata(expiresAt: 110.0)))
            ->map(static fn (int $first, string $second): string => $first.$second);

        self::assertSame('1two', $result->value());
        self::assertSame(110.0, $result->metadata->expiresAt);
    }

    public function testCombine3CreatesCapability3(): void
    {
        $result = Cached::of(1)
            ->combine3(Cached::of('two'), Cached::of(true))
            ->map(static fn (int $first, string $second, bool $third): array => [$first, $second, $third]);

        self::assertSame([1, 'two', true], $result->value());
    }

    public function testCombine4CreatesCapability4(): void
    {
        $result = Cached::of(1)
            ->combine4(Cached::of('two'), Cached::of(true), Cached::of(4.0))
            ->map(
                static fn (int $first, string $second, bool $third, float $fourth): array => [
                    $first,
                    $second,
                    $third,
                    $fourth,
                ],
            );

        self::assertSame([1, 'two', true, 4.0], $result->value());
    }

    public function testCombine5CreatesCapability5(): void
    {
        $result = Cached::of(1)
            ->combine5(
                Cached::of('two'),
                Cached::of(true),
                Cached::of(4.0),
                Cached::of(null),
            )
            ->map(
                static fn (int $first, string $second, bool $third, float $fourth, null $fifth): array => [
                    $first,
                    $second,
                    $third,
                    $fourth,
                    $fifth,
                ],
            );

        self::assertSame([1, 'two', true, 4.0, null], $result->value());
    }

    public function testCombine6CreatesCapability6(): void
    {
        $result = Cached::of(1)
            ->combine6(
                Cached::of('two'),
                Cached::of(true),
                Cached::of(4.0),
                Cached::of(null),
                Cached::of(6),
            )
            ->map(
                static fn (int $first, string $second, bool $third, float $fourth, null $fifth, int $sixth): array => [
                    $first,
                    $second,
                    $third,
                    $fourth,
                    $fifth,
                    $sixth,
                ],
            );

        self::assertSame([1, 'two', true, 4.0, null, 6], $result->value());
    }

    public function testCombine7CreatesCapability7(): void
    {
        $result = Cached::of(1)
            ->combine7(
                Cached::of('two'),
                Cached::of(true),
                Cached::of(4.0),
                Cached::of(null),
                Cached::of(6),
                Cached::of('seven'),
            )
            ->map(
                static fn (int $first, string $second, bool $third, float $fourth, null $fifth, int $sixth, string $seventh): array => [
                    $first,
                    $second,
                    $third,
                    $fourth,
                    $fifth,
                    $sixth,
                    $seventh,
                ],
            );

        self::assertSame([1, 'two', true, 4.0, null, 6, 'seven'], $result->value());
    }

    public function testCombine8CreatesCapability8(): void
    {
        $result = Cached::of(1)
            ->combine8(
                Cached::of('two'),
                Cached::of(true),
                Cached::of(4.0),
                Cached::of(null),
                Cached::of(6),
                Cached::of('seven'),
                Cached::of(false),
            )
            ->map(
                static fn (int $first, string $second, bool $third, float $fourth, null $fifth, int $sixth, string $seventh, bool $eighth): array => [
                    $first,
                    $second,
                    $third,
                    $fourth,
                    $fifth,
                    $sixth,
                    $seventh,
                    $eighth,
                ],
            );

        self::assertSame([1, 'two', true, 4.0, null, 6, 'seven', false], $result->value());
    }

    public function testCombine9CreatesCapability9(): void
    {
        $result = Cached::of(1)
            ->combine9(
                Cached::of('two'),
                Cached::of(true),
                Cached::of(4.0),
                Cached::of(null),
                Cached::of(6),
                Cached::of('seven'),
                Cached::of(false),
                Cached::of(9.0),
            )
            ->map(
                static fn (int $first, string $second, bool $third, float $fourth, null $fifth, int $sixth, string $seventh, bool $eighth, float $ninth): array => [
                    $first,
                    $second,
                    $third,
                    $fourth,
                    $fifth,
                    $sixth,
                    $seventh,
                    $eighth,
                    $ninth,
                ],
            );

        self::assertSame([1, 'two', true, 4.0, null, 6, 'seven', false, 9.0], $result->value());
    }

    public function testCombine10CreatesCapability10(): void
    {
        $result = Cached::of(1)
            ->combine10(
                Cached::of('two'),
                Cached::of(true),
                Cached::of(4.0),
                Cached::of(null),
                Cached::of(6),
                Cached::of('seven'),
                Cached::of(false),
                Cached::of(9.0),
                Cached::of(null),
            )
            ->map(
                static fn (int $first, string $second, bool $third, float $fourth, null $fifth, int $sixth, string $seventh, bool $eighth, float $ninth, null $tenth): array => [
                    $first,
                    $second,
                    $third,
                    $fourth,
                    $fifth,
                    $sixth,
                    $seventh,
                    $eighth,
                    $ninth,
                    $tenth,
                ],
            );

        self::assertSame([1, 'two', true, 4.0, null, 6, 'seven', false, 9.0, null], $result->value());
    }

    public function testValueSupportsExplicitObjectAndStringAccess(): void
    {
        $cached = Cached::of(new KeyDto(7));

        self::assertSame(7, $cached->value()->id);
        self::assertSame('key:7', $cached->value()->label());
        self::assertSame('text', Cached::of('text')->value());
    }

    public function testMapSupportsArrayFunctionsAndPreservesTheirResultKeys(): void
    {
        $items = Cached::of([1, 2, 3], new CacheMetadata(expiresAt: 120.0, tags: ['items']));
        $excluded = Cached::of([2], new CacheMetadata(expiresAt: 110.0, tags: ['excluded']));
        $difference = $items->combine2($excluded)->map(
            static fn (array $items, array $excluded): array => array_diff($items, $excluded),
        );
        self::assertSame([0 => 1, 2 => 3], $difference->value());
        self::assertSame(110.0, $difference->metadata->expiresAt);
        self::assertSame(['excluded', 'items'], $difference->metadata->tags);
    }

    public function testFlattenMeetsAllConstraintsAndKeepsExpiredDependenciesExpired(): void
    {
        $outerMetadata = new CacheMetadata(expiresAt: 120.0, tags: ['outer'], visibility: Visibility::Private);
        $innerMetadata = new CacheMetadata(
            expiresAt: 90.0,
            cacheable: false,
            tags: ['inner'],
            visibility: Visibility::NoStore,
            reasons: ['stale'],
        );
        $inner = Cached::of(null, $innerMetadata);
        $nested = Cached::of($inner, $outerMetadata);

        $flattened = $nested->flatten();

        self::assertSame(null, $flattened->value());
        self::assertTrue((new CacheMetadata(
            expiresAt: 90.0,
            cacheable: false,
            tags: ['inner', 'outer'],
            visibility: Visibility::NoStore,
            reasons: ['stale'],
        ))->equals($flattened->metadata));
        self::assertFalse($flattened->metadata->isStorable(100.0));
        self::assertSame($inner, $nested->value());
        self::assertSame($outerMetadata, $nested->metadata);
        self::assertSame($innerMetadata, $inner->metadata);
    }

    public function testFlattenRemovesOnlyOneLayerAndMatchesFlatMap(): void
    {
        $inner = Cached::of('product', new CacheMetadata(expiresAt: 110.0, tags: ['inner']));
        $middle = Cached::of($inner, new CacheMetadata(expiresAt: 120.0, tags: ['middle']));
        $outer = Cached::of($middle, new CacheMetadata(expiresAt: 130.0, tags: ['outer']));

        $once = $outer->flatten();
        $twice = $once->flatten();
        $bound = $outer->flatMap(static fn (Cached $value): Cached => $value)->flatten();

        self::assertSame($inner, $once->value());
        self::assertSame(120.0, $once->metadata->expiresAt);
        self::assertSame(['middle', 'outer'], $once->metadata->tags);
        self::assertSame('product', $twice->value());
        self::assertSame(110.0, $twice->metadata->expiresAt);
        self::assertSame(['inner', 'middle', 'outer'], $twice->metadata->tags);
        self::assertSame($bound->value(), $twice->value());
        self::assertTrue($bound->metadata->equals($twice->metadata));
    }

    public function testZipPairsDifferentTypesAndMeetsAllConstraints(): void
    {
        $first = Cached::of(42, new CacheMetadata(expiresAt: 120.0, tags: ['product'], reasons: ['first']));
        $second = Cached::of(null, new CacheMetadata(
            expiresAt: 90.0,
            cacheable: false,
            tags: ['viewer'],
            visibility: Visibility::NoStore,
            reasons: ['second'],
        ));

        $pair = $first->zip($second);

        self::assertSame([42, null], $pair->value());
        self::assertTrue((new CacheMetadata(
            expiresAt: 90.0,
            cacheable: false,
            tags: ['product', 'viewer'],
            visibility: Visibility::NoStore,
            reasons: ['first', 'second'],
        ))->equals($pair->metadata));
    }

    public function testUnzipKeepsTheWholePairsConstraintsOnEachProjection(): void
    {
        $pair = Cached::of(42, new CacheMetadata(expiresAt: 120.0, tags: ['product']))
            ->zip(Cached::of(null, new CacheMetadata(expiresAt: 90.0, tags: ['viewer'], visibility: Visibility::Private)));

        [$first, $second] = $pair->unzip();

        self::assertSame(42, $first->value());
        self::assertSame(null, $second->value());
        self::assertSame($pair->metadata, $first->metadata);
        self::assertSame($pair->metadata, $second->metadata);
        self::assertFalse($first->metadata->isStorable(100.0));
        self::assertTrue($pair->metadata->equals($first->zip($second)->metadata));
    }

    public function testSequenceCollectsArraysWithoutLosingKeysOrConstraints(): void
    {
        $result = Cached::sequence([
            'featured' => Cached::of('product', new CacheMetadata(expiresAt: 120.0, tags: ['product'])),
            7 => Cached::of('inventory', new CacheMetadata(expiresAt: 110.0, tags: ['stock'], visibility: Visibility::Private)),
        ]);

        self::assertSame(['featured' => 'product', 7 => 'inventory'], $result->value());
        self::assertSame(110.0, $result->metadata->expiresAt);
        self::assertSame(['product', 'stock'], $result->metadata->tags);
        self::assertSame(Visibility::Private, $result->metadata->visibility);
    }

    public function testSequenceConsumesGeneratorsOnceAndKeepsConstraintsOfOverwrittenKeys(): void
    {
        $items = (static function (): Generator {
            yield 'same' => Cached::of('old', new CacheMetadata(
                expiresAt: 90.0,
                cacheable: false,
                tags: ['old'],
                visibility: Visibility::NoStore,
                reasons: ['stale'],
            ));
            yield 'same' => Cached::of('new', new CacheMetadata(expiresAt: 120.0, tags: ['new']));
            yield 7 => Cached::of(null);
        })();

        $result = Cached::sequence($items);

        self::assertFalse($items->valid());
        self::assertSame(['same' => 'new', 7 => null], $result->value());
        self::assertSame(['same' => 'new', 7 => null], $result->value());
        self::assertTrue((new CacheMetadata(
            expiresAt: 90.0,
            cacheable: false,
            tags: ['new', 'old'],
            visibility: Visibility::NoStore,
            reasons: ['stale'],
        ))->equals($result->metadata));
    }

    public function testSequenceOfEmptyInputHasIdentityMetadata(): void
    {
        $result = Cached::sequence([]);

        self::assertSame([], $result->value());
        self::assertTrue(CacheMetadata::top()->equals($result->metadata));
    }

    public function testTraverseEagerlyMapsEveryItemOnceInOrderAndMeetsAllConstraints(): void
    {
        $calls = [];
        $result = Cached::traverse(
            ['featured' => 3, 7 => 1, 'last' => 2],
            static function (int $id) use (&$calls): Cached {
                $calls[] = $id;

                return Cached::of('product:'.$id, new CacheMetadata(
                    expiresAt: 100.0 + $id,
                    cacheable: $id !== 1,
                    tags: ['product:'.$id],
                    visibility: $id === 1 ? Visibility::NoStore : Visibility::Shared,
                    reasons: $id === 1 ? ['restricted'] : [],
                ));
            },
        );

        self::assertSame([3, 1, 2], $calls);
        self::assertSame(['featured' => 'product:3', 7 => 'product:1', 'last' => 'product:2'], $result->value());
        self::assertSame([3, 1, 2], $calls);
        self::assertTrue((new CacheMetadata(
            expiresAt: 101.0,
            cacheable: false,
            tags: ['product:1', 'product:2', 'product:3'],
            visibility: Visibility::NoStore,
            reasons: ['restricted'],
        ))->equals($result->metadata));
    }

    public function testTraverseOfEmptyInputDoesNotCallTheTransform(): void
    {
        $calls = 0;
        $result = Cached::traverse([], static function (int $id) use (&$calls): Cached {
            ++$calls;

            return Cached::of($id);
        });

        self::assertSame(0, $calls);
        self::assertSame([], $result->value());
        self::assertTrue(CacheMetadata::top()->equals($result->metadata));
    }
}
