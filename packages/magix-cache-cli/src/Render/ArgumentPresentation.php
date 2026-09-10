<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use Magix\Cache\Cli\Declaration\ParameterReference;
use Magix\Cache\Cli\Declaration\Unresolved;
use UnitEnum;

/**
 * Encodes declaration values without losing unknowns or executing enum construction.
 */
final readonly class ArgumentPresentation
{
    /**
     * Tagged values distinguish an unreadable expression from a literal null or array.
     *
     * @return array<string, mixed>
     */
    public function data(mixed $value): array
    {
        if ($value === Unresolved::Value) {
            return ['state' => 'unknown'];
        }

        if ($value instanceof ParameterReference) {
            return ['state' => 'runtime', 'parameter' => $value->name];
        }

        if ($value instanceof UnitEnum) {
            return ['state' => 'known', 'enum' => $value::class, 'case' => $value->name];
        }

        return is_array($value)
            ? ['state' => 'known', 'items' => array_map($this->data(...), $value)]
            : ['state' => 'known', 'value' => $value];
    }
}
