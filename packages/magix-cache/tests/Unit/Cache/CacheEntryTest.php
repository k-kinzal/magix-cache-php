<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use const INF;

use InvalidArgumentException;
use Magix\Cache\Cache\CacheEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheEntry::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class CacheEntryTest extends TestCase
{
    public function testFiniteAbsoluteExpirationIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CacheEntry('value', INF);
    }

    public function testValueReturnsInternalValueWithConcreteMetadata(): void
    {
        $entry = new CacheEntry(
            'value',
            120.0,
            tags: ['product:1'],
        );

        self::assertSame(120.0, $entry->expiresAt);
        self::assertSame('value', $entry->value());
        self::assertSame(['product:1'], $entry->tags);
    }

    public function testWithRetainedUntilPreservesValueAndLogicalMetadata(): void
    {
        $entry = new CacheEntry('value', 120.0, tags: ['product:1']);
        $retained = $entry->withRetainedUntil(150.0);

        self::assertSame('value', $retained->value());
        self::assertSame(120.0, $retained->expiresAt);
        self::assertSame(150.0, $retained->retainedUntil);
        self::assertSame(['product:1'], $retained->tags);
    }

    public function testRetentionCannotPrecedeLogicalExpiration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CacheEntry('value', 120.0, retainedUntil: 119.0);
    }
}
