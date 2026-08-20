<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use PhpParser\Node\Arg;
use PhpParser\Node\VariadicPlaceholder;

/**
 * Binds written call arguments to the parameter names they belong to.
 */
final readonly class ArgumentReader
{
    /**
     * Creates an argument reader.
     */
    public function __construct(private LiteralReader $literals = new LiteralReader())
    {
    }

    /**
     * Returns the value written for each named or positional parameter.
     *
     * @param array<Arg|VariadicPlaceholder> $arguments
     * @param list<string> $names
     * @return array<string, mixed>
     */
    public function values(array $arguments, array $names): array
    {
        $values = [];
        $position = 0;

        foreach ($arguments as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                continue;
            }

            if ($argument->name !== null) {
                $values[$argument->name->toString()] = $this->literals->value($argument->value);

                continue;
            }

            $name = $names[$position] ?? null;
            ++$position;

            if ($name !== null) {
                $values[$name] = $this->literals->value($argument->value);
            }
        }

        return $values;
    }
}
