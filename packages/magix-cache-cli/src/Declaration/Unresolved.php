<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Marks a written value the analyzer cannot read without executing code.
 *
 * The marker keeps "not statically readable" apart from every real value,
 * including null: a declaration that writes null carries null, and only an
 * expression the reader had to give up on carries this case. The analyzer
 * never fills an unresolved value with a default.
 */
enum Unresolved
{
    /**
     * The written expression could not be read statically.
     */
    case Value;
}
