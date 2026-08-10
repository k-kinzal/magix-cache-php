<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use InvalidArgumentException;

use function is_callable;

use Magix\Cache\Attribute\CacheKey;
use ReflectionParameter;

/**
 * Applies an optional cache-key reducer declared on one parameter.
 *
 * @internal
 */
final readonly class CacheKeyReducer
{
    /**
     * Applies the parameter's reducer or returns the original value.
     */
    public function reduce(ReflectionParameter $parameter, mixed $value): mixed
    {
        $reducers = $parameter->getAttributes(CacheKey::class);

        if ($reducers === []) {
            return $value;
        }

        $reducer = $reducers[0]->newInstance()->reduce;

        if (!is_callable($reducer)) {
            throw new InvalidArgumentException('Cache key reducer for $'.$parameter->getName().' is not callable.');
        }

        return $reducer($value);
    }
}
