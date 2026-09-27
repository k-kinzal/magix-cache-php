<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Invariance;

use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Records the existing value domain, including intentional unresolved results.
 *
 * A resolver replacement must not silently turn incomplete analysis into a
 * different declaration, even when it supports more PHP than this reader.
 */
#[CoversClass(LiteralReader::class)]
final class LiteralBaselineTest extends TestCase
{
    /**
     * Resolves a parsed expression to exactly its pre-refactoring result.
     */
    #[DataProvider('expressions')]
    public function testValuePreservesTheExistingResolution(string $source, mixed $expected): void
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php '.$source.';');
        self::assertNotNull($statements);
        self::assertInstanceOf(Expression::class, $statements[0]);
        self::assertSame($expected, (new LiteralReader())->value($statements[0]->expr));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function expressions(): iterable
    {
        $resolved = [
            '0' => 0,
            '60' => 60,
            '0.25' => 0.25,
            "'product'" => 'product',
            'true' => true,
            'FALSE' => false,
            'null' => null,
            '+60' => 60,
            '-0.25' => -0.25,
            '60 + 5' => 65,
            '60 - 5' => 55,
            '60 * 5' => 300,
            '60 / 5' => 12,
            '61 / 2' => 30.5,
            '2 ** 8' => 256,
            '62 % 60' => 2,
            '12 & 5' => 4,
            '12 | 5' => 13,
            '12 ^ 5' => 9,
            '3 << 2' => 12,
            '12 >> 2' => 3,
            "'v' . 7" => 'v7',
            "'v' . 0.25" => 'v0.25',
            '[]' => [],
            "['product', 'catalog']" => ['product', 'catalog'],
            "['a' => [1, null, false], 4 => 'b', 'c']" => ['a' => [1, null, false], 4 => 'b', 5 => 'c'],
            "['a' => 1, 'a' => 2]" => ['a' => 2],
            "['2' => 'a', 'b']" => [2 => 'a', 3 => 'b'],
            "[null => 'a']" => ['a'],
            'UnknownClass::class' => 'UnknownClass',
            'Magix\\Cache\\Metadata\\Visibility::Private' => Visibility::Private,
            'Magix\\Cache\\Runtime\\Policy\\Ttl::Auto' => Ttl::Auto,
        ];

        foreach ($resolved as $source => $expected) {
            yield (string) $source => [(string) $source, $expected];
        }

        foreach ([
            '$ttl', 'missing()', 'new UnknownClass()', 'UNKNOWN_CONSTANT', 'UnknownClass::TTL',
            '60 / 0', '60 / 0.0', '60 % 0', '3 << -1', '3 >> -1',
            "'60' + 5", '1.5 % 2', '1.5 & 2', "-'60'", "+'60'", "'v' . true",
            'true && false', 'true || false', '1 === 1', '1 < 2', '!false', '~1',
            'true ? 30 : 60', 'null ?? 60', "['ttl' => 60]['ttl']",
            '[...[]]', '[true => 1]', '[1.5 => 1]', '[UNKNOWN_CONSTANT]',
        ] as $source) {
            yield $source => [$source, LiteralReader::UNRESOLVED];
        }
    }
}
