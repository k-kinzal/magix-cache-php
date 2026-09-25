<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use Magix\Cache\AsyncCached;
use Magix\Cache\Composition\AsyncCapability3;
use Magix\Cache\Metadata\CacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\UsesNamespace('Magix\Cache')]
#[CoversClass(AsyncCapability3::class)]
#[UsesClass(AsyncCached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class AsyncCapability3Test extends TestCase
{
    public function testMapTransformsThreeTypedValues(): void
    {
        $result = AsyncCached::of(1)
            ->combine3(AsyncCached::of('two'), AsyncCached::of(true))
            ->map(static fn (int $first, string $second, bool $third): string => $first.$second.(int) $third);

        self::assertSame('1two1', $result->value());
    }
}
