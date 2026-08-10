<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\CacheKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Reducer;

#[CoversClass(CacheKey::class)]
final class CacheKeyTest extends TestCase
{
    public function testAttributePreservesReducer(): void
    {
        $attribute = new CacheKey([Reducer::class, 'parity']);

        self::assertSame([Reducer::class, 'parity'], $attribute->reduce);
    }
}
