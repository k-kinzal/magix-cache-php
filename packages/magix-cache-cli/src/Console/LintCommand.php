<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Console;

use function array_filter;
use function count;
use function json_encode;

use Magix\Cache\Cli\Lint\CacheLinter;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\Severity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports cache boundaries that cannot behave the way they are declared.
 */
#[AsCommand(
    name: 'lint',
    description: 'Reports cache boundaries that fail, never store, or leak between viewers',
    help: 'Applies every MagixCache rule to the boundaries of a project and exits with a failure when errors are found.',
)]
final readonly class LintCommand
{
    /**
     * Creates the lint command.
     */
    public function __construct(
        private CatalogLoader $catalog,
        private CacheLinter $linter = new CacheLinter(),
    ) {
    }

    /**
     * Reports every finding and returns a failure when the run is not clean.
     *
     * @param array<array-key, mixed> $path
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Directory or file to scan, repeatable', name: 'path')]
        array $path = [],
        #[Option(description: 'Output format: text or json')]
        string $format = 'text',
        #[Option(description: 'Fail when warnings are reported as well')]
        bool $strict = false,
    ): int {
        $diagnostics = $this->linter->inspect($this->catalog->load($path));

        if ($format === 'json') {
            $io->writeln($this->encode($diagnostics));
        } else {
            foreach ($diagnostics as $diagnostic) {
                $io->writeln($this->describe($diagnostic));
            }
        }

        $errors = count(array_filter($diagnostics, static fn (Diagnostic $found): bool => $found->severity === Severity::Error));
        $warnings = count(array_filter($diagnostics, static fn (Diagnostic $found): bool => $found->severity === Severity::Warning));

        if ($format !== 'json') {
            $io->writeln(count($diagnostics).' findings, '.$errors.' errors, '.$warnings.' warnings');
        }

        return $errors > 0 || ($strict && $warnings > 0) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Returns one finding rendered for the terminal.
     */
    public function describe(Diagnostic $diagnostic): string
    {
        $color = match ($diagnostic->severity) {
            Severity::Error => 'red',
            Severity::Warning => 'yellow',
            Severity::Notice => 'cyan',
        };

        return $diagnostic->file.':'.$diagnostic->line
            .'  <fg='.$color.'>'.$diagnostic->severity->value.'</>'
            .'  '.$diagnostic->rule
            ."\n  ".$diagnostic->boundary.': '.$diagnostic->message
            .($diagnostic->hint === '' ? '' : "\n  hint: ".$diagnostic->hint)
            ."\n";
    }

    /**
     * Returns every finding encoded as JSON.
     *
     * @param list<Diagnostic> $diagnostics
     */
    public function encode(array $diagnostics): string
    {
        $encoded = json_encode(array_map(
            static fn (Diagnostic $diagnostic): array => [
                'rule' => $diagnostic->rule,
                'severity' => $diagnostic->severity->value,
                'boundary' => $diagnostic->boundary,
                'file' => $diagnostic->file,
                'line' => $diagnostic->line,
                'message' => $diagnostic->message,
                'hint' => $diagnostic->hint,
            ],
            $diagnostics,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $encoded;
    }
}
