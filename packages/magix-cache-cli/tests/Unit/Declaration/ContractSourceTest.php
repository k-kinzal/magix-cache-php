<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\ContractSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContractSource::class)]
final class ContractSourceTest extends TestCase
{
    public function testSourcesAreDistinct(): void
    {
        self::assertNotSame(ContractSource::Constructor, ContractSource::Create);
    }
}
