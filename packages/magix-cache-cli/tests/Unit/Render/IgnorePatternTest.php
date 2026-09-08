<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Render\IgnorePattern;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IgnorePattern::class)]
#[UsesClass(BoundaryDeclaration::class)]
final class IgnorePatternTest extends TestCase
{
    #[DataProvider('providerPatterns')]
    public function testMatchesWholeClassAndMethodNames(string $pattern, bool $expected, bool $cached): void
    {
        $boundary = new BoundaryDeclaration('App\Query\InventoryManager', 'getStock', 'query.php', 1, isCacheBoundary: $cached);

        self::assertSame($expected, (new IgnorePattern($pattern))->matches($boundary));
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function providerPatterns(): iterable
    {
        foreach ([false, true] as $cached) {
            yield 'class prefix'.($cached ? ' cached' : ' ordinary') => ['Inventory*', true, $cached];
            yield 'class suffix'.($cached ? ' cached' : ' ordinary') => ['*Manager', true, $cached];
            yield 'exact class'.($cached ? ' cached' : ' ordinary') => ['InventoryManager', true, $cached];
            yield 'whole name'.($cached ? ' cached' : ' ordinary') => ['Inventory', false, $cached];
            yield 'method only'.($cached ? ' cached' : ' ordinary') => ['*::get*', true, $cached];
            yield 'class and method'.($cached ? ' cached' : ' ordinary') => ['Inventory*::getStock', true, $cached];
            yield 'method mismatch'.($cached ? ' cached' : ' ordinary') => ['Inventory*::set*', false, $cached];
            yield 'single character'.($cached ? ' cached' : ' ordinary') => ['InventoryManage?::getStoc?', true, $cached];
            yield 'single character required'.($cached ? ' cached' : ' ordinary') => ['InventoryManager?::getStock', false, $cached];
            yield 'namespace'.($cached ? ' cached' : ' ordinary') => ['App\Query\*', true, $cached];
            yield 'nested namespaces'.($cached ? ' cached' : ' ordinary') => ['App\*', true, $cached];
            yield 'absolute namespace'.($cached ? ' cached' : ' ordinary') => ['\App\Query\Inventory*::get*', true, $cached];
            yield 'different namespace'.($cached ? ' cached' : ' ordinary') => ['Other\Query\*', false, $cached];
            yield 'namespace is not a short name'.($cached ? ' cached' : ' ordinary') => ['Query\*', false, $cached];
            yield 'case sensitive'.($cached ? ' cached' : ' ordinary') => ['inventory*', false, $cached];
            yield 'no regex syntax'.($cached ? ' cached' : ' ordinary') => ['Inventory(Manager)', false, $cached];
            yield 'no character classes'.($cached ? ' cached' : ' ordinary') => ['Inventory[M]anager', false, $cached];
            yield 'no negation'.($cached ? ' cached' : ' ordinary') => ['!Inventory*', false, $cached];
        }
    }

    public function testGlobTreatsWildcardsAsCharactersAndEverythingElseLiterally(): void
    {
        $pattern = new IgnorePattern('*');

        self::assertTrue($pattern->glob('名?', '名前'));
        self::assertTrue($pattern->glob('get*', 'get'));
        self::assertFalse($pattern->glob('get?', 'get'));
        self::assertTrue($pattern->glob('a.b', 'a.b'));
        self::assertFalse($pattern->glob('a.b', 'axb'));
    }
}
