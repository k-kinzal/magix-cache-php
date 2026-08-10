<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\CacheIgnore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheIgnore::class)]
final class CacheIgnoreTest extends TestCase
{
    public function testAttributeCanBeInstantiated(): void
    {
        self::assertEquals(new CacheIgnore(), new CacheIgnore());
    }
}
