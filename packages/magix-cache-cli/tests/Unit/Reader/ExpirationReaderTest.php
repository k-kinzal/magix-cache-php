<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Cli\Reader\ExpirationReader;
use PhpParser\Node\Attribute;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpirationReader::class)]
#[UsesNamespace('Magix\Cache\Cli\Declaration')]
#[UsesNamespace('Magix\Cache\Cli\Reader')]
final class ExpirationReaderTest extends TestCase
{
    public function testReadPreservesConstructorReferencesAndExplicitNulls(): void
    {
        $attribute = new Attribute(new \PhpParser\Node\Name('ExpiresAt'), [
            new \PhpParser\Node\Arg(new \PhpParser\Node\Expr\New_(new \PhpParser\Node\Name(\Magix\Cache\Strategy\Contract\ConstructorArg::class), [new \PhpParser\Node\Arg(new \PhpParser\Node\Scalar\String_('cutoff'))])),
        ]);
        $contract = (new ExpirationReader())->read($attribute);

        self::assertEquals(new ContractReference(ContractSource::Constructor, 'cutoff'), $contract->at);
        self::assertNull($contract->until);
        self::assertSame('UTC', $contract->timezone);
    }

    #[DataProvider('providerDeclarations')]
    public function testReadKeepsInvalidAndUnknownSourceForAnalysis(string $arguments, mixed $at, mixed $zone, bool $problem): void
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php #[ExpiresAt('.$arguments.')] function fetch() {}') ?? [];
        $attribute = (new NodeFinder())->findFirstInstanceOf($nodes, Attribute::class);
        self::assertInstanceOf(Attribute::class, $attribute);
        $contract = (new ExpirationReader())->read($attribute);

        self::assertSame($at, $contract->at);
        self::assertSame($zone, $contract->timezone);
        self::assertSame($problem, $contract->problems !== []);
    }

    /**
     * @return iterable<string, array{string, mixed, mixed, bool}>
     */
    public static function providerDeclarations(): iterable
    {
        yield 'positional' => ["'12:00', '12:15', 'Asia/Tokyo'", '12:00', 'Asia/Tokyo', false];
        yield 'named' => ["timezone: 'Asia/Tokyo', at: '12:00'", '12:00', 'Asia/Tokyo', false];
        yield 'null at' => ['null', null, 'UTC', false];
        yield 'null timezone' => ["'12:00', timezone: null", '12:00', null, false];
        yield 'unknown' => ['AtRuntime::TIME', Unresolved::Value, 'UTC', false];
        yield 'object' => ['new stdClass()', Unresolved::Value, 'UTC', true];
        yield 'missing' => ['', Unresolved::Value, 'UTC', true];
        yield 'duplicate' => ["'12:00', at: '13:00'", '13:00', 'UTC', true];
        yield 'extra' => ["'12:00', extra: '13:00'", '12:00', 'UTC', true];
    }
}
