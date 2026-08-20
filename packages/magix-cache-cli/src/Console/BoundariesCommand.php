<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Console;

use function array_filter;
use function array_map;
use function array_values;
use function count;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Render\BoundaryTableRenderer;
use Magix\Cache\Cli\Render\JsonRenderer;

use function str_contains;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists every cache boundary a project declares.
 */
#[AsCommand(
    name: 'boundaries',
    description: 'Lists every cache boundary with its effective TTL, scope and tags',
    aliases: ['ls'],
    help: 'Builds an inventory of every cached() call site so that cache coverage can be reviewed at a glance.',
)]
final readonly class BoundariesCommand
{
    /**
     * Creates the boundaries command.
     */
    public function __construct(private CatalogLoader $catalog)
    {
    }

    /**
     * Renders the boundary inventory.
     *
     * @param array<array-key, mixed> $path
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Directory or file to scan, repeatable', name: 'path')]
        array $path = [],
        #[Option(description: 'Output format: table or json')]
        string $format = 'table',
        #[Option(description: 'Only list boundaries whose identifier contains this text')]
        string $filter = '',
        #[Option(description: 'Maximum dependency depth used to compute effective values')]
        int $depth = 8,
    ): int {
        $catalog = $this->catalog->load($path);
        $boundaries = array_values(array_filter(
            $catalog->boundaries(),
            static fn (BoundaryDeclaration $boundary): bool => $filter === '' || str_contains($boundary->id(), $filter),
        ));

        if ($boundaries === []) {
            $io->warning('No cache boundaries were found. Use --path to point at the directory that contains them.');

            return Command::SUCCESS;
        }

        $tree = new CacheTree($catalog);
        $nodes = array_map(static fn (BoundaryDeclaration $boundary): CacheNode => $tree->build($boundary, $depth), $boundaries);

        if ($format === 'json') {
            $io->writeln((new JsonRenderer())->render($nodes));

            return Command::SUCCESS;
        }

        $io->writeln((new BoundaryTableRenderer())->render($nodes));
        $io->writeln(count($nodes).' boundaries');

        return Command::SUCCESS;
    }
}
