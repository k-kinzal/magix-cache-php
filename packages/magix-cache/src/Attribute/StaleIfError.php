<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;
use InvalidArgumentException;

use function is_a;

use Throwable;

/**
 * Serves a retained expired entry when the origin fails with a declared exception.
 *
 * Only the origin call is inside the capture range, and only the exception
 * types declared here are eligible: an empty list and a blanket Throwable are
 * both rejected, and a PHP Error never triggers the fallback implicitly.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class StaleIfError
{
    /**
     * Declares the stale-if-error behavior for a boundary.
     *
     * @param int $maxAge Maximum seconds past expiration a retained value may still stand in.
     * @param list<string> $exceptions Throwable class names eligible for the fallback.
     * @param bool $enabled Explicit false disables a class-level declaration.
     * @throws InvalidArgumentException when the maximum age is negative, the enabled declaration captures nothing, or a declared type is Throwable itself or not a Throwable
     */
    public function __construct(
        public int $maxAge = 0,
        public array $exceptions = [],
        public bool $enabled = true,
    ) {
        if ($maxAge < 0) {
            throw new InvalidArgumentException('Stale maximum age must be zero or greater.');
        }

        if (!$enabled) {
            return;
        }

        if ($exceptions === []) {
            throw new InvalidArgumentException('StaleIfError requires at least one declared exception type.');
        }

        foreach ($exceptions as $type) {
            if ($type === Throwable::class) {
                throw new InvalidArgumentException('StaleIfError must declare concrete exception types, not Throwable itself.');
            }

            if (!is_a($type, Throwable::class, true)) {
                throw new InvalidArgumentException('StaleIfError can only declare Throwable types, got "'.$type.'".');
            }
        }
    }

    /**
     * Reports whether the failure matches one of the declared exception types.
     */
    public function captures(Throwable $error): bool
    {
        foreach ($this->exceptions as $type) {
            if (is_a($error, $type)) {
                return true;
            }
        }

        return false;
    }
}
