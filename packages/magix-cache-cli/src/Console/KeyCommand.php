<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Console;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_string;
use function json_decode;
use function json_encode;

use JsonException;
use Magix\Cache\Cli\Key\CacheKeyResolver;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Prints the cache key that one call to a boundary produces.
 */
#[AsCommand(
    name: 'key',
    description: 'Prints the cache key a call to one boundary produces',
    help: 'Binds the given arguments the way Cacheable does and hashes them with the default key strategy, which makes an entry findable in the cache backend.',
)]
final readonly class KeyCommand
{
    /**
     * Creates the key command.
     */
    public function __construct(
        private CatalogLoader $catalog,
        private CacheKeyResolver $keys = new CacheKeyResolver(),
    ) {
    }

    /**
     * Resolves and prints the key of one call.
     *
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $path
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Boundary to key, for example ProductQuery::execute')]
        string $boundary,
        #[Argument(description: 'Call arguments in parameter order, each a JSON value')]
        array $arguments = [],
        #[Option(description: 'Directory or file to scan, repeatable', name: 'path')]
        array $path = [],
    ): int {
        $matches = $this->catalog->load($path)->search($boundary);

        if (count($matches) !== 1) {
            $io->error($matches === []
                ? 'No cache boundary matches "'.$boundary.'".'
                : 'The reference "'.$boundary.'" matches '.count($matches).' boundaries; use the fully qualified name.');

            return Command::FAILURE;
        }

        $found = $matches[0];
        $values = $this->decode($arguments);

        try {
            $bound = $this->keys->arguments($found->class, $found->method, $values);
            $key = $this->keys->resolve($found->class, $found->method, $found->policy->version ?? '1', $values);
        } catch (Throwable $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln($found->id());
        $io->writeln('  version    '.($found->policy->version ?? '1'));
        $io->writeln('  arguments  '.($bound === [] ? 'none' : implode(', ', array_map(
            static fn (string $name, mixed $value): string => $name.'='.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            array_keys($bound),
            $bound,
        ))));
        $io->writeln('  key        '.$key);

        return Command::SUCCESS;
    }

    /**
     * Returns command line arguments decoded as JSON values.
     *
     * @param array<array-key, mixed> $arguments
     * @return list<mixed>
     */
    public function decode(array $arguments): array
    {
        return array_map(
            static function (string $argument): mixed {
                try {
                    return json_decode($argument, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    return $argument;
                }
            },
            array_values(array_filter($arguments, is_string(...))),
        );
    }
}
