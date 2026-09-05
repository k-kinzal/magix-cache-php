<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\DynamicTtl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\FixedTtlResolver;

#[CoversClass(DynamicTtl::class)]
final class DynamicTtlTest extends TestCase
{
    public function testDeclarationCarriesOnlyTheResolverReference(): void
    {
        $behavior = new DynamicTtl(resolver: FixedTtlResolver::class);

        self::assertSame(FixedTtlResolver::class, $behavior->resolver);
        self::assertTrue($behavior->enabled);
    }

    public function testDisablingDeclarationNeedsNoResolver(): void
    {
        self::assertFalse((new DynamicTtl(enabled: false))->enabled);
    }
}
