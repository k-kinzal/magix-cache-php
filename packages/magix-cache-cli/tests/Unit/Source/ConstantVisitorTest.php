<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Source;

use function base64_encode;

use Magix\Cache\Cli\Declaration\ConstantCatalog;
use Magix\Cache\Cli\Source\ConstantVisitor;
use Magix\Cache\Cli\Source\SourceParser;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstantVisitor::class)]
#[UsesClass(ConstantCatalog::class)]
#[UsesClass(SourceParser::class)]
final class ConstantVisitorTest extends TestCase
{
    public function testEnterNodeCollectsClassAndInterfaceConstantsThroughInheritance(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;
            interface Limits { public const int CEILING = 900; }
            class Defaults implements Limits { public const int TTL = 300; }
            class Page extends Defaults {}
            PHP;
        $catalog = (new SourceParser())->constants('data:text/plain;base64,'.base64_encode($source));

        $declared = $catalog->classConstant('App\Defaults', 'TTL');
        $inherited = $catalog->classConstant('App\Page', 'TTL');
        $fromInterface = $catalog->classConstant('App\Page', 'CEILING');

        self::assertInstanceOf(Int_::class, $declared);
        self::assertSame(300, $declared->value);
        self::assertSame($declared, $inherited);
        self::assertInstanceOf(Int_::class, $fromInterface);
        self::assertSame(900, $fromInterface->value);
    }

    public function testCatalogExposesBackedEnumCasesAndNamespacedConstants(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;
            const WINDOW = 45;
            enum Channel: string { case Web = 'web'; }
            enum Mode { case Fast; }
            PHP;
        $catalog = (new SourceParser())->constants('data:text/plain;base64,'.base64_encode($source));

        $case = $catalog->classConstant('App\Channel', 'Web');
        $window = $catalog->globalConstant('App\WINDOW');

        self::assertInstanceOf(String_::class, $case);
        self::assertSame('web', $case->value);
        self::assertInstanceOf(Int_::class, $window);
        self::assertSame(45, $window->value);
        self::assertNull($catalog->classConstant('App\Mode', 'Fast'));
    }

    public function testAncestorsListsTheTypesConstantsAreInheritedFrom(): void
    {
        $class = new Class_(new Identifier('Page'), [
            'extends' => new Name('Defaults'),
            'implements' => [new Name('Limits')],
        ]);

        self::assertSame(['Defaults', 'Limits'], (new ConstantVisitor())->ancestors($class));
        self::assertSame([], (new ConstantVisitor())->ancestors(new Class_(new Identifier('Bare'))));
    }
}
