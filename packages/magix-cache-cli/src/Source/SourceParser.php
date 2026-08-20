<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Source;

use function file_get_contents;

use Magix\Cache\Cli\Declaration\ClassDeclaration;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Parses one PHP file into the class declarations it contains.
 */
final readonly class SourceParser
{
    /**
     * Parser used for every scanned file.
     */
    private Parser $parser;

    /**
     * Creates a source parser.
     */
    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Returns every class declared in one file.
     *
     * @param string|null $display Path recorded in the declarations, defaulting to the read path.
     * @return list<ClassDeclaration>
     */
    public function parse(string $file, ?string $display = null): array
    {
        $code = file_get_contents($file);

        if ($code === false) {
            throw new RuntimeException('Unable to read '.$file.'.');
        }

        $statements = $this->parser->parse($code) ?? [];
        $resolver = new NodeTraverser(new NameResolver());
        $statements = $resolver->traverse($statements);
        $visitor = new ClassVisitor($display ?? $file);
        $collector = new NodeTraverser($visitor);
        $collector->traverse($statements);

        return $visitor->declarations();
    }
}
