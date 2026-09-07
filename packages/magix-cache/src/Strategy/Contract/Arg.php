<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy\Contract;

use InvalidArgumentException;

/**
 * An explicit contract reference to one argument of the declaring method.
 *
 * On a create() declaration this binds to the create() argument of that
 * name, so an assumption can follow the same value the construction code
 * receives. The analyzer resolves the reference against the declared
 * parameters; a reference to a parameter that does not exist is a
 * declaration error, never a silent unknown.
 */
final readonly class Arg
{
    /**
     * Creates a reference to one method argument.
     *
     * @throws InvalidArgumentException when the name is empty
     */
    public function __construct(public string $name)
    {
        if ($name === '') {
            throw new InvalidArgumentException('A method argument reference must name a parameter.');
        }
    }
}
