<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\MetadataContract;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use Magix\Cache\Cli\Declaration\TtlAssumption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrategyDeclaration::class)]
#[UsesClass(MetadataContract::class)]
#[UsesClass(TtlAssumption::class)]
final class StrategyDeclarationTest extends TestCase
{
    public function testShortNameStripsTheNamespace(): void
    {
        $declaration = new StrategyDeclaration('App\Cache\ProductCacheStrategy', 'src/ProductCacheStrategy.php', 7);

        self::assertSame('App\Cache\ProductCacheStrategy', $declaration->name);
        self::assertSame('src/ProductCacheStrategy.php', $declaration->file);
        self::assertSame(7, $declaration->line);
        self::assertSame('ProductCacheStrategy', $declaration->shortName());
    }

    public function testShortNameKeepsANameWithoutANamespace(): void
    {
        $declaration = new StrategyDeclaration('ProductCacheStrategy');

        self::assertSame('', $declaration->file);
        self::assertSame(0, $declaration->line);
        self::assertSame([], $declaration->parameters);
        self::assertSame([], $declaration->createParameters);
        self::assertFalse($declaration->hasCreate);
        self::assertNull($declaration->composed);
        self::assertNull($declaration->ttl);
        self::assertSame([], $declaration->notes);
        self::assertSame('ProductCacheStrategy', $declaration->shortName());
    }

    public function testAssumptionForReturnsTheAssumptionDeclaredForTheComposedClass(): void
    {
        $assumption = new TtlAssumption('App\Cache\RedisStrategy', min: 30);
        $declaration = new StrategyDeclaration('App\Cache\ProductCacheStrategy', assumptions: [$assumption]);

        self::assertSame($assumption, $declaration->assumptionFor('App\Cache\RedisStrategy'));
    }

    public function testAssumptionForReturnsNullWhenNoAssumptionCoversTheClass(): void
    {
        $declaration = new StrategyDeclaration('App\Cache\ProductCacheStrategy');

        self::assertSame([], $declaration->assumptions);
        self::assertNull($declaration->assumptionFor('App\Cache\RedisStrategy'));
    }
}
