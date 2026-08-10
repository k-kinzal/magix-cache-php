<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use Magix\Cache\Cached;
use Magix\Cache\Composition\Capability3;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Capability3::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\ConstraintMeet::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\Visibility::class)]
final class Capability3Test extends TestCase
{
    public function testMapTransformsThreeTypedValues(): void
    {
        $result = Cached::of(1)
            ->combine3(Cached::of('two'), Cached::of(true))
            ->map(static fn (int $first, string $second, bool $third): string => $first.$second.(int) $third);

        self::assertSame('1two1', $result->value());
    }
}
