<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheScope::class)]
final class CacheScopeTest extends TestCase
{
    public function testAttributeDefaultsToPrivate(): void
    {
        self::assertSame(Visibility::Private, (new CacheScope())->visibility);
    }
}
