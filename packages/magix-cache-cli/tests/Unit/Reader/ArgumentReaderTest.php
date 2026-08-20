<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Reader\ArgumentReader;
use Magix\Cache\Cli\Reader\LiteralReader;
use PhpParser\Node\Arg;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArgumentReader::class)]
#[UsesClass(LiteralReader::class)]
final class ArgumentReaderTest extends TestCase
{
    public function testValuesBindPositionalAndNamedArguments(): void
    {
        $values = (new ArgumentReader())->values(
            [new Arg(new Int_(20)), new Arg(new String_('v2'), name: new Identifier('version'))],
            ['ttl', 'maxTtl'],
        );

        self::assertSame(['ttl' => 20, 'version' => 'v2'], $values);
    }

    public function testValuesIgnoreUnpackedAndSurplusArguments(): void
    {
        $values = (new ArgumentReader())->values(
            [new Arg(new Int_(20), unpack: true), new Arg(new Int_(30))],
            ['ttl'],
        );

        self::assertSame(['ttl' => 30], $values);
    }
}
