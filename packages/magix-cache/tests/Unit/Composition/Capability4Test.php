<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use Magix\Cache\Cached;
use Magix\Cache\Composition\Capability4;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Capability4::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\ConstraintMeet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\Visibility::class)]
final class Capability4Test extends TestCase
{
    public function testMapTransformsFourTypedValues(): void
    {
        $result = Cached::of(1)
            ->combine4(Cached::of(2.5), Cached::of('three'), Cached::of(false))
            ->map(static fn (int $first, float $second, string $third, bool $fourth): array => [
                $first,
                $second,
                $third,
                $fourth,
            ]);

        self::assertSame([1, 2.5, 'three', false], $result->value());
    }
}
