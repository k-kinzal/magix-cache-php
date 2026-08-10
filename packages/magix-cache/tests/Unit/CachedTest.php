<?php

declare(strict_types=1);

namespace Tests\Unit;

use Magix\Cache\Cached;
use Magix\Cache\Composition\Capability2;
use Magix\Cache\Composition\Capability3;
use Magix\Cache\Composition\Capability4;
use Magix\Cache\Composition\Capability5;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\KeyDto;

#[CoversClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\ConstraintMeet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\Visibility::class)]
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
        self::assertEquals(CacheMetadata::top(), $result->metadata);
    }

    public function testValueReturnsWrappedValue(): void
    {
        $value = new KeyDto(1);

        self::assertSame($value, Cached::of($value)->value());
    }

    public function testCombine2CreatesCapability2(): void
    {
        $result = Cached::of(1)
            ->combine2(Cached::of('two'))
            ->map(static fn (int $first, string $second): string => $first.$second);

        self::assertSame('1two', $result->value());
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
