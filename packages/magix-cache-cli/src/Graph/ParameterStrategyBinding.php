<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\ParameterReference;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use Magix\Cache\Cli\Declaration\UseStrategyDeclaration;

/**
 * Supplies unknown invocation values to the same factory destinations as runtime.
 */
final readonly class ParameterStrategyBinding
{
    /**
     * @return array{UseStrategyDeclaration, list<string>}
     */
    public function bind(BoundaryDeclaration $boundary, UseStrategyDeclaration $use, StrategyDeclaration $declaration): array
    {
        $arguments = $use->arguments;
        $parameters = [];
        $problems = [];

        foreach ($declaration->createParameters as $parameter) {
            $parameters[$parameter->name] = $parameter;
        }

        foreach ($boundary->parameters as $source) {
            $target = $source->configuration?->strategyArgument;

            if ($target === null) {
                continue;
            }

            $parameter = $parameters[$target] ?? null;

            if ($parameter === null || $parameter->variadic || $parameter->byReference) {
                $problems[] = '$'.$source->name.' requires a declared non-variadic value parameter $'.$target.' on create()';

                continue;
            }

            if (array_key_exists($target, $arguments) || array_key_exists($parameter->position, $arguments)) {
                $problems[] = 'multiple values supply strategy argument $'.$target;

                continue;
            }

            $arguments[$target] = new ParameterReference($source->name);
        }

        return [new UseStrategyDeclaration($use->strategy, $arguments, $use->line), $problems];
    }
}
