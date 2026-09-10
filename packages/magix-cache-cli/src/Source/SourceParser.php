<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Source;

use function array_values;
use function file_get_contents;

use Magix\Cache\Cli\Declaration\ClassDeclaration;
use Magix\Cache\Cli\Declaration\ConstantCatalog;
use Magix\Cache\Cli\Reader\ArgumentReader;
use Magix\Cache\Cli\Reader\BoundaryReader;
use Magix\Cache\Cli\Reader\ContractReader;
use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Cli\Reader\ParameterReader;
use Magix\Cache\Cli\Reader\PolicyReader;
use Magix\Cache\Cli\Reader\StrategyReader;
use Magix\Cache\Cli\Reader\TypeReader;
use Magix\Cache\Cli\Reader\UseStrategyReader;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Parses one PHP file into the class declarations it contains.
 *
 * Constants are collected in a pass of their own, because an attribute in one
 * file may reference a constant declared in another. Files are read twice
 * rather than held as syntax trees, so a large project costs time and not memory.
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
     * Returns the constants one file declares, for the reading pass to resolve.
     *
     * @throws RuntimeException when the file cannot be read
     */
    public function constants(string $file): ConstantCatalog
    {
        $visitor = new ConstantVisitor();
        (new NodeTraverser($visitor))->traverse($this->statements($file));

        return $visitor->catalog();
    }

    /**
     * Returns every class declared in one file.
     *
     * @param string|null $display Path recorded in the declarations, defaulting to the read path.
     * @param ConstantCatalog $constants Constants of every scanned file, so referenced values resolve.
     * @return list<ClassDeclaration>
     * @throws RuntimeException when the file cannot be read
     */
    public function parse(string $file, ?string $display = null, ConstantCatalog $constants = new ConstantCatalog()): array
    {
        $literals = new LiteralReader($constants);
        $arguments = new ArgumentReader($literals);
        $visitor = new ClassVisitor(
            $display ?? $file,
            new BoundaryReader(
                policies: new PolicyReader($arguments),
                parameters: new ParameterReader(literals: $literals),
                arguments: $arguments,
                useStrategies: new UseStrategyReader($literals),
            ),
            new TypeReader(),
            new StrategyReader(new ContractReader($literals), literals: $literals, arguments: $arguments),
        );
        (new NodeTraverser($visitor))->traverse($this->statements($file));

        return $visitor->declarations();
    }

    /**
     * Returns the name-resolved statements of one file.
     *
     * @return list<Node>
     * @throws RuntimeException when the file cannot be read
     */
    public function statements(string $file): array
    {
        $code = file_get_contents($file);

        if ($code === false) {
            throw new RuntimeException('Unable to read '.$file.'.');
        }

        return array_values((new NodeTraverser(new NameResolver()))->traverse($this->parser->parse($code) ?? []));
    }
}
