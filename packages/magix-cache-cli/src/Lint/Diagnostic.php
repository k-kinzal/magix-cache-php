<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Lint;

/**
 * Holds one finding about a cache boundary.
 */
final readonly class Diagnostic
{
    /**
     * Creates a diagnostic.
     *
     * @param string $rule Stable identifier of the rule that produced the finding.
     * @param string $hint Change that resolves the finding.
     */
    public function __construct(
        public string $rule,
        public Severity $severity,
        public string $boundary,
        public string $file,
        public int $line,
        public string $message,
        public string $hint = '',
    ) {
    }
}
