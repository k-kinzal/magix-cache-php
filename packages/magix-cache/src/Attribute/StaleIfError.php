<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;
use InvalidArgumentException;

use function is_a;

use RuntimeException;

/**
 * Serves a retained expired entry when the origin fails with a declared exception.
 *
 * Only the origin call is inside the capture range, and only failures the
 * origin declares as behavior are eligible: every declared type must be a
 * RuntimeException subtype, and an empty list is rejected. Bugs — the
 * LogicException family and PHP Errors — are never RuntimeException, so they
 * can never trigger the fallback; an origin that meets an expected outage in
 * a foreign exception hierarchy translates it into its own declared type.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class StaleIfError
{
    /**
     * Declares the stale-if-error behavior for a boundary.
     *
     * @param int $maxAge Maximum seconds past expiration a retained value may still stand in.
     * @param list<string> $exceptions RuntimeException subtype names eligible for the fallback.
     * @param bool $enabled Explicit false disables a class-level declaration.
     * @throws InvalidArgumentException when the maximum age is negative, the enabled declaration captures nothing, or a declared type is not a RuntimeException subtype
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
            if (!is_a($type, RuntimeException::class, true)) {
                throw new InvalidArgumentException('StaleIfError can only declare RuntimeException subtypes, got "'.$type.'"; translate an expected outage into a declared RuntimeException at the origin.');
            }
        }
    }

}
