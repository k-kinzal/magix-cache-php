<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\ExpirationContract;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Cli\Graph\ExpirationBinding;
use Magix\Cache\Cli\Graph\ExpirationEstimate;
use Magix\Cache\Strategy\Contract\ExpiresAt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpirationBinding::class)]
#[UsesClass(ExpirationContract::class)]
#[UsesClass(ContractReference::class)]
#[UsesClass(ExpirationEstimate::class)]
#[UsesClass(ExpiresAt::class)]
final class ExpirationBindingTest extends TestCase
{
    public function testResolveBindsAllFieldsAndPreservesUnknownInvocationValues(): void
    {
        $contract = new ExpirationContract(
            new ContractReference(ContractSource::Constructor, 'at'),
            new ContractReference(ContractSource::Constructor, 'until'),
            new ContractReference(ContractSource::Constructor, 'timezone'),
        );
        $binding = new ExpirationBinding();
        [$known, $problems] = $binding->resolve($contract, ['at' => '12:00', 'until' => '12:15', 'timezone' => 'Asia/Tokyo'], 'fetch');
        [$unknown, $unknownProblems] = $binding->resolve($contract, ['at' => Unresolved::Value, 'until' => Unresolved::Value, 'timezone' => Unresolved::Value], 'fetch');
        [$single] = $binding->resolve($contract, ['at' => '12:00', 'until' => null, 'timezone' => 'UTC'], 'fetch');

        self::assertSame('daily 12:00-12:15 Asia/Tokyo', $known->label());
        self::assertSame([], $problems);
        self::assertSame('daily ?-? ?', $unknown->label());
        self::assertSame([], $unknownProblems);
        self::assertFalse($single->window);
    }

    public function testValueDistinguishesMissingReferencesFromUnknownValues(): void
    {
        $binding = new ExpirationBinding();
        [$value, $missing] = $binding->value(new ContractReference(ContractSource::Constructor, 'missing'), []);
        [, $wrongSource] = $binding->value(new ContractReference(ContractSource::Create, 'at'), ['at' => '12:00']);

        self::assertSame(Unresolved::Value, $value);
        self::assertSame("references ConstructorArg('missing'), but no such parameter is declared", $missing);
        self::assertSame("Arg('at') cannot bind here; use ConstructorArg", $wrongSource);
        self::assertSame(['12:00', null], $binding->value('12:00', []));
    }

    #[DataProvider('providerMalformedValues')]
    public function testProblemReportsMalformedSourceDeclarations(string $field, mixed $value): void
    {
        self::assertNotNull((new ExpirationBinding())->problem($field, $value));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function providerMalformedValues(): iterable
    {
        yield 'hour' => ['at', '24:00'];
        yield 'minute' => ['until', '12:60'];
        yield 'second' => ['at', '12:00:60'];
        yield 'nonpadded' => ['at', '9:00'];
        yield 'newline' => ['at', "12:00\n"];
        yield 'integer' => ['at', 1200];
        yield 'null at' => ['at', null];
        yield 'zone' => ['timezone', 'No/Such_Zone'];
        yield 'null zone' => ['timezone', null];
    }

    public function testResolveRetainsProblemsFromReadingAndBinding(): void
    {
        [, $problems] = (new ExpirationBinding())->resolve(new ExpirationContract('25:00', timezone: 'No/Such_Zone', problems: ['duplicate at']), [], 'fetch');

        self::assertSame(['fetch: duplicate at', 'fetch: at must be a valid HH:MM or HH:MM:SS time', 'fetch: timezone must be an IANA timezone identifier'], $problems);
    }
}
