<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use function explode;
use function ltrim;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;

use function preg_match;
use function preg_quote;
use function str_contains;
use function strtr;

/**
 * Matches a short or fully qualified class name and an optional method glob.
 */
final readonly class IgnorePattern
{
    /**
     * Creates a display pattern using only * and ? as wildcards.
     */
    public function __construct(private string $pattern)
    {
    }

    /**
     * Reports whether the complete declared name matches, regardless of caching.
     */
    public function matches(BoundaryDeclaration $boundary): bool
    {
        $parts = explode('::', $this->pattern, 2);
        $class = str_contains($parts[0], '\\')
            ? $boundary->class
            : explode('::', $boundary->shortId(), 2)[0];

        return $this->glob(ltrim($parts[0], '\\'), $class)
            && $this->glob($parts[1] ?? '*', $boundary->method);
    }

    /**
     * Matches the whole name, treating namespace separators as literal characters.
     */
    public function glob(string $pattern, string $name): bool
    {
        $expression = strtr(preg_quote($pattern, '~'), ['\\*' => '.*', '\\?' => '.']);

        return preg_match('~\A'.$expression.'\z~u', $name) === 1;
    }
}
