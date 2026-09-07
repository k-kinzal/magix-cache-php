<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function is_string;

use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Cli\Declaration\UseStrategyDeclaration;
use PhpParser\Node\Attribute;

/**
 * Reads one #[UseStrategy] declaration from a boundary.
 */
final readonly class UseStrategyReader
{
    /**
     * Creates a use-strategy reader.
     */
    public function __construct(private LiteralReader $literals = new LiteralReader())
    {
    }

    /**
     * Returns the declared usage, or null when it is disabled or unreadable.
     */
    public function read(Attribute $attribute): ?UseStrategyDeclaration
    {
        $strategy = null;
        $enabled = true;
        $arguments = [];
        $position = 0;

        foreach ($attribute->args as $argument) {
            if ($argument->unpack) {
                continue;
            }

            $name = $argument->name?->toString();
            $value = $this->literals->value($argument->value);
            $value = $value === LiteralReader::UNRESOLVED ? Unresolved::Value : $value;

            if ($name === 'strategy' || ($name === null && $position === 0)) {
                $strategy = $value;
            } elseif ($name === 'enabled' || ($name === null && $position === 1)) {
                $enabled = $value;
            } elseif ($name !== null) {
                $arguments[$name] = $value;
            } else {
                $arguments[] = $value;
            }

            if ($name === null) {
                ++$position;
            }
        }

        if ($enabled === false || !is_string($strategy) || $strategy === '') {
            return null;
        }

        return new UseStrategyDeclaration($strategy, $arguments, $attribute->getStartLine());
    }
}
