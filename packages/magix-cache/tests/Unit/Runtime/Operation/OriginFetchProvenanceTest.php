<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Operation;

use Magix\Cache\Runtime\Operation\OriginFetchProvenance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriginFetchProvenance::class)]
final class OriginFetchProvenanceTest extends TestCase
{
    public function testProvenancesAreDistinct(): void
    {
        self::assertNotSame(OriginFetchProvenance::Origin, OriginFetchProvenance::Stale);
    }
}
