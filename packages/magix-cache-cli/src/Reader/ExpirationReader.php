<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ExpirationContract;
use Magix\Cache\Cli\Declaration\Unresolved;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\New_;

/**
 * Reads wall-clock contracts without constructing attributes or running strategies.
 */
final readonly class ExpirationReader
{
    /**
     * Preserves invalid argument shapes as analysis problems.
     */
    public function read(Attribute $attribute): ExpirationContract
    {
        $values = [];
        $problems = [];
        $names = ['at', 'until', 'timezone'];
        $position = 0;
        $named = false;
        $reader = new ContractReader();

        foreach ($attribute->args as $argument) {
            if ($argument->unpack) {
                $problems[] = 'expiration arguments cannot use unpacking';

                continue;
            }

            $name = $argument->name?->toString() ?? ($names[$position++] ?? '');

            if (!in_array($name, $names, true) || array_key_exists($name, $values) || ($named && $argument->name === null)) {
                $problems[] = 'invalid or duplicate expiration argument '.$name;
            }

            $named = $named || $argument->name !== null;
            $values[$name] = $reader->value($argument->value);

            if ($argument->value instanceof New_ && !$values[$name] instanceof ContractReference) {
                $problems[] = 'expiration objects must be valid ConstructorArg references';
            }
        }

        if (!array_key_exists('at', $values)) {
            $problems[] = 'an expiration contract requires at';
        }

        return new ExpirationContract(
            at: array_key_exists('at', $values) ? $values['at'] : Unresolved::Value,
            until: $values['until'] ?? null,
            timezone: array_key_exists('timezone', $values) ? $values['timezone'] : 'UTC',
            problems: $problems,
        );
    }
}
