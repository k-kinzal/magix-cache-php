<?php

declare(strict_types=1);

namespace Tests\Unit;

use Magix\Cache\Cached;
use Magix\Cache\Composition\Capability2;
use Magix\Cache\Composition\Capability3;
use Magix\Cache\Composition\Capability4;
use Magix\Cache\Composition\Capability5;
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

    public function testMagicAccessForwardsPropertiesAndMethods(): void
    {
        $cached = Cached::of(new KeyDto(7));

        self::assertSame(7, $cached->id);
        self::assertSame('key:7', $cached->label());
    }

    public function testMagicAccessSupportsStrings(): void
    {
        self::assertSame('text', (string) Cached::of('text'));
    }
}
