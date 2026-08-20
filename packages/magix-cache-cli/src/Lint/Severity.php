<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Lint;

/**
 * Describes how strongly a diagnostic argues against the current code.
 */
enum Severity: string
{
    /**
     * The boundary fails or never caches at runtime.
     */
    case Error = 'error';

    /**
     * The boundary works but can return or store the wrong entry.
     */
    case Warning = 'warning';

    /**
     * The declaration contains something that has no effect.
     */
    case Notice = 'notice';
}
