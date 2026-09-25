<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use Magix\Cache\AsyncCached;
use Magix\Cache\Composition\AsyncCapability4;
use Magix\Cache\Metadata\CacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\UsesNamespace('Magix\Cache')]
#[CoversClass(AsyncCapability4::class)]
#[UsesClass(AsyncCached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class AsyncCapability4Test extends TestCase
{
    public function testMapTransformsFourTypedValues(): void
    {
        $result = AsyncCached::of(1)
            ->combine4(AsyncCached::of(2.5), AsyncCached::of('three'), AsyncCached::of(false))
            ->map(static fn (int $first, float $second, string $third, bool $fourth): array => [
                $first,
                $second,
                $third,
                $fourth,
            ]);

        self::assertSame([1, 2.5, 'three', false], $result->value());
    }
}
