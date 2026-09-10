<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Console;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function is_string;

use JsonException;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Render\IgnorePattern;
use Magix\Cache\Cli\Render\JsonRenderer;
use Magix\Cache\Cli\Render\MermaidRenderer;
use Magix\Cache\Cli\Render\TreeFilter;
use Magix\Cache\Cli\Render\TreeRenderer;
use Magix\Cache\Cli\Render\UncachedMode;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows the composed caches of a boundary or an uncached entry point.
 */
#[AsCommand(
    name: 'analyze',
    description: 'Shows the cache tree of a boundary or an uncached entry point',
    help: 'Reads source without running it and composes the cache boundaries called by a cached method or an uncached entry point, such as a controller action.',
)]
final readonly class AnalyzeCommand
{
    /**
     * Creates the analyze command.
     */
    public function __construct(private CatalogLoader $catalog)
    {
    }

    /**
     * Renders the cache tree of the referenced boundary.
     *
     * Analysis covers the whole reachable graph, bounded only by recursion.
     * Display depth, uncached modes and ignore patterns filter the finished
     * tree afterwards, so printing less never changes what was analyzed.
     *
     * @param array<array-key, mixed> $path
     * @param array<array-key, mixed> $ignore
     * @param UncachedMode $uncached Select rows by Cache attributes, independently of execution, types and diagnostics; the selected root follows the same rule.
     * @throws JsonException when the tree cannot be encoded as JSON
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Boundary or entry point to analyze, for example FooBarQuery::execute or HomeController::index')]
        string $boundary,
        #[Option(description: 'Directory or file to scan, repeatable', name: 'path')]
        array $path = [],
        #[Option(description: 'Output format: tree, json or mermaid')]
        string $format = 'tree',
        #[Option(description: 'Maximum dependency depth to print; analysis always covers the whole graph')]
        int $depth = 8,
        #[Option(description: 'Hide matching class or Class::method subtrees (* and ? wildcards), repeatable')]
        array $ignore = [],
        #[Option(description: 'Rows without #[Cache]: between declarations (default), all, or none; applies to the selected root too')]
        UncachedMode $uncached = UncachedMode::Between,
    ): int {
        $catalog = $this->catalog->load($path);
        $matches = $catalog->search($boundary, includeEntryPoints: true);

        if ($matches === []) {
            $io->error('No cache boundary or entry point matches "'.$boundary.'".');
            $io->writeln($this->suggestions($catalog->boundaries()));

            return Command::FAILURE;
        }

        $tree = new CacheTree($catalog);
        $filter = new TreeFilter(
            array_values(array_map(static fn (string $pattern): IgnorePattern => new IgnorePattern($pattern), array_filter($ignore, is_string(...)))),
            $uncached,
            $depth,
        );
        $nodes = array_merge(...array_map(
            static fn (BoundaryDeclaration $found): array => $filter->apply($tree->build($found, includeUncached: true)),
            $matches,
        ));

        return $this->render($io, $nodes, $format);
    }

    /**
     * Renders the filtered trees without recalculating their analysis results.
     *
     * @param list<CacheNode> $nodes
     * @throws JsonException when the tree cannot be encoded as JSON
     */
    public function render(SymfonyStyle $io, array $nodes, string $format): int
    {
        if ($format === 'json') {
            $io->writeln((new JsonRenderer())->render($nodes));

            return Command::SUCCESS;
        }

        if ($format === 'mermaid' && $nodes !== []) {
            $io->writeln((new MermaidRenderer())->forest($nodes));

            return Command::SUCCESS;
        }

        $renderer = new TreeRenderer();

        if ($nodes === []) {
            $io->writeln('No methods match the display filters.');
        }

        foreach ($nodes as $node) {
            $io->write($renderer->render($node));
        }

        return Command::SUCCESS;
    }

    /**
     * Returns the boundaries that can be analyzed instead.
     *
     * @param list<BoundaryDeclaration> $boundaries
     */
    public function suggestions(array $boundaries): string
    {
        if ($boundaries === []) {
            return 'No cache boundaries were found. Use --path to point at the directory that contains them.';
        }

        $known = array_map(static fn (BoundaryDeclaration $found): string => '  '.$found->id(), array_slice($boundaries, 0, 10));
        $more = count($boundaries) > 10 ? ['  ... and '.(count($boundaries) - 10).' more'] : [];

        return implode("\n", ['Known boundaries:', ...$known, ...$more]);
    }
}
