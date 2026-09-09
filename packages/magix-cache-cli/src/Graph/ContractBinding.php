<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use function array_key_exists;
use function is_int;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\StrategyArgument;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use Magix\Cache\Cli\Declaration\StrategyParameter;
use Magix\Cache\Cli\Declaration\TtlContract;
use Magix\Cache\Cli\Declaration\Unresolved;

/**
 * Binds written arguments to declared parameters and resolves references.
 *
 * Binding follows the same rules PHP applies at the call site: positional
 * arguments in order, named arguments by name, and declared defaults for the
 * rest. A reference to a parameter that does not exist is a declaration
 * error; a value the analysis cannot read stays unresolved and is never
 * replaced with a default or a guess.
 */
final readonly class ContractBinding
{
    /**
     * Binds the declared create() arguments of a boundary.
     *
     * @param array<array-key, mixed> $arguments
     * @return array{array<string, mixed>, list<string>}
     */
    public function bindCreate(StrategyDeclaration $declaration, array $arguments): array
    {
        return $this->bind($declaration->createParameters, $arguments, $declaration->shortName().'::create()');
    }

    /**
     * Binds the written constructor arguments of one composed strategy.
     *
     * The result carries the values passed to the constructor; what a
     * constructor stores after normalizing is a different thing and is
     * never assumed to be the same.
     *
     * @param list<StrategyArgument> $arguments
     * @param array<string, mixed> $environment Bound create() parameters.
     * @return array{array<string, mixed>, list<string>}
     */
    public function bindConstructor(StrategyDeclaration $child, array $arguments, array $environment): array
    {
        return $this->bind($child->parameters, $this->values($arguments, $environment), 'the constructor of '.$child->shortName());
    }

    /**
     * Returns written arguments as plain values, following variables.
     *
     * @param list<StrategyArgument> $arguments
     * @param array<string, mixed> $environment
     * @return array<array-key, mixed>
     */
    public function values(array $arguments, array $environment): array
    {
        $values = [];

        foreach ($arguments as $argument) {
            $value = $argument->variable === null
                ? $argument->value
                : (array_key_exists($argument->variable, $environment) ? $environment[$argument->variable] : Unresolved::Value);

            if ($argument->name === null) {
                $values[] = $value;
            } else {
                $values[$argument->name] = $value;
            }
        }

        return $values;
    }

    /**
     * Resolves one declared bound to a value the analysis can use.
     *
     * @param int|ContractReference|Unresolved|null $declared
     * @param ContractSource $source The reference source this position can bind.
     * @param array<string, mixed> $environment
     * @return array{int|null, string|null} Resolved bound and the declaration problem, when one exists.
     */
    public function bound(int|ContractReference|Unresolved|null $declared, ContractSource $source, array $environment, string $subject): array
    {
        if ($declared === null || is_int($declared)) {
            return [$declared, null];
        }

        if ($declared instanceof Unresolved) {
            return [null, null];
        }

        if ($declared->source !== $source) {
            return [null, $subject.' uses '.$declared->label().', which this declaration position cannot bind'];
        }

        if (!array_key_exists($declared->name, $environment)) {
            return [null, $subject.' references '.$declared->label().', but no such parameter is declared'];
        }

        $value = $environment[$declared->name];

        return [is_int($value) ? $value : null, null];
    }

    /**
     * Returns the candidate estimate two resolved bounds describe.
     *
     * Equal bounds pin the constraint; a missing bound stays undetermined
     * rather than unlimited, and contradicting bounds cannot work as
     * written.
     */
    public function estimate(?int $min, ?int $max, string $subject): TtlEstimate
    {
        if (($min !== null && $min < 0) || ($max !== null && $max < 0)) {
            return TtlEstimate::invalid('the resolved lifetime bounds of '.$subject.' must be zero or greater');
        }

        if ($min !== null && $max !== null && $max < $min) {
            return TtlEstimate::invalid('the resolved lifetime bounds of '.$subject.' contradict ('.$min.'s > '.$max.'s)');
        }

        if ($min !== null && $min === $max) {
            return TtlEstimate::known($min);
        }

        if ($min === null && $max === null) {
            return TtlEstimate::unknown(condition: 'the declared lifetime bounds of '.$subject.' are not statically resolved', finite: true);
        }

        return TtlEstimate::unknown(upperBound: $max, lowerBound: $min, finite: true);
    }

    /**
     * Resolves every alternative and reports declaration errors to the lint rule.
     *
     * @param array<string, mixed> $environment
     * @return array{TtlEstimate, list<string>}
     */
    public function contract(TtlContract $contract, ContractSource $source, array $environment, string $subject): array
    {
        $problems = $contract->declarationProblems();

        if ($problems !== []) {
            return [TtlEstimate::invalid($problems[0]), $problems];
        }

        if ($contract->oneOf !== null) {
            return $this->alternatives($contract->oneOf, $source, $environment, $subject);
        }

        if ($contract->unconstrained) {
            return [TtlEstimate::unconstrained(), []];
        }

        [$min, $minProblem] = $this->bound($contract->min, $source, $environment, $subject);
        [$max, $maxProblem] = $this->bound($contract->max, $source, $environment, $subject);
        $problems = array_values(array_filter([$minProblem, $maxProblem], static fn (?string $problem): bool => $problem !== null));
        $estimate = $problems === [] ? $this->estimate($min, $max, $subject) : TtlEstimate::invalid($problems[0]);

        if ($estimate->state === TtlEstimateState::Invalid && $estimate->reason !== null && $problems === []) {
            $problems[] = $estimate->reason;
        }

        return [$estimate, $problems];
    }

    /**
     * Unions possible constraints rather than meeting mutually exclusive paths.
     *
     * @param list<TtlContract> $alternatives
     * @param array<string, mixed> $environment
     * @return array{TtlEstimate, list<string>}
     */
    public function alternatives(array $alternatives, ContractSource $source, array $environment, string $subject): array
    {
        $ranges = [];
        $problems = [];
        $condition = null;

        foreach ($alternatives as $index => $alternative) {
            [$estimate, $errors] = $this->contract($alternative, $source, $environment, $subject.' alternative '.($index + 1));
            $problems = [...$problems, ...$errors];
            $ranges = [...$ranges, ...$estimate->ranges()->intervals];
            $condition ??= $estimate->reason;
        }

        if ($problems !== []) {
            return [TtlEstimate::invalid($problems[0]), $problems];
        }

        if ($ranges === []) {
            return [TtlEstimate::invalid('lifetime alternatives must not be empty'), ['lifetime alternatives must not be empty']];
        }

        return [TtlEstimate::fromRanges(new TtlRangeSet(...$ranges), $condition, finite: true), []];
    }

    /**
     * Binds arguments to parameters the way PHP would at the call site.
     *
     * @param list<StrategyParameter> $parameters
     * @param array<array-key, mixed> $arguments
     * @return array{array<string, mixed>, list<string>}
     */
    public function bind(array $parameters, array $arguments, string $subject): array
    {
        $byPosition = [];
        $declared = [];

        foreach ($parameters as $parameter) {
            $byPosition[$parameter->position] = $parameter->name;
            $declared[$parameter->name] = true;
        }

        $values = [];
        $problems = [];
        $position = 0;

        foreach ($arguments as $key => $value) {
            $name = is_int($key) ? ($byPosition[$position++] ?? null) : $key;

            if ($name === null) {
                continue;
            }

            if (!isset($declared[$name])) {
                $problems[] = $subject.' has no parameter $'.$name.', so the declared arguments cannot be bound';

                continue;
            }

            $values[$name] = $value;
        }

        foreach ($parameters as $parameter) {
            if (array_key_exists($parameter->name, $values)) {
                continue;
            }

            if ($parameter->hasDefault) {
                $values[$parameter->name] = $parameter->default;

                continue;
            }

            $problems[] = $subject.' is missing a value for $'.$parameter->name;
            $values[$parameter->name] = Unresolved::Value;
        }

        return [$values, $problems];
    }
}
