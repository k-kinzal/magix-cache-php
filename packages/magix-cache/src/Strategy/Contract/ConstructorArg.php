<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy\Contract;

use InvalidArgumentException;

/**
 * An explicit contract reference to one constructor argument by name.
 *
 * The reference binds to the value passed to the constructor of the strategy
 * that declares the contract, not to a normalized property the constructor
 * may derive from it. The analyzer resolves the reference against the
 * construction code; the name alone never makes it guess a meaning.
 */
final readonly class ConstructorArg
{
    /**
     * Creates a reference to one constructor argument.
     *
     * @throws InvalidArgumentException when the name is empty
     */
    public function __construct(public string $name)
    {
        if ($name === '') {
            throw new InvalidArgumentException('A constructor argument reference must name a parameter.');
        }
    }
}
