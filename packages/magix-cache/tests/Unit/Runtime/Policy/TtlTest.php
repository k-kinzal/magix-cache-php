<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Policy;

use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ttl::class)]
final class TtlTest extends TestCase
{
    public function testAutoIsTheOnlyModeBecauseItOnlyMeansNoDeclaredLifetime(): void
    {
        self::assertSame([Ttl::Auto], Ttl::cases());
    }
}
