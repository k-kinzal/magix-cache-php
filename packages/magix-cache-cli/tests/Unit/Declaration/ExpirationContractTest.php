<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\ExpirationContract;
use Magix\Cache\Cli\Declaration\Unresolved;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpirationContract::class)]
final class ExpirationContractTest extends TestCase
{
    public function testPreservesUnknownAndMalformedSourceValues(): void
    {
        $unknown = new ExpirationContract();
        $malformed = new ExpirationContract(at: 1200, until: '12:15', problems: ['invalid time']);

        self::assertSame(Unresolved::Value, $unknown->at);
        self::assertSame('UTC', $unknown->timezone);
        self::assertSame(1200, $malformed->at);
        self::assertSame(['invalid time'], $malformed->problems);
    }
}
