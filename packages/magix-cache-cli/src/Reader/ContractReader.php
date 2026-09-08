<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function is_int;
use function is_string;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\TtlAssumption;
use Magix\Cache\Cli\Declaration\TtlContract;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Strategy\Contract\Arg as ArgReference;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\TtlRange;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

/**
 * Reads lifetime contracts and assumptions written as attributes.
 *
 * A contract value may be a constant or an explicit reference such as
 * new ConstructorArg('minimum'); anything else stays unresolved instead of
 * being guessed.
 */
final readonly class ContractReader
{
    /**
     * Creates a contract reader.
     */
    public function __construct(private LiteralReader $literals = new LiteralReader())
    {
    }

    /**
     * Returns the lifetime contract one attribute declares.
     */
    public function ttl(Attribute $attribute): TtlContract
    {
        return $this->declaration($this->values($attribute->args, []));
    }

    /**
     * Returns the assumption one attribute declares, when it names a strategy.
     */
    public function assumption(Attribute $attribute): ?TtlAssumption
    {
        $values = $this->values($attribute->args, ['strategy']);
        $strategy = $values['strategy'] ?? null;

        if (!is_string($strategy) || $strategy === '') {
            return null;
        }

        unset($values['strategy']);
        $contract = $this->declaration($values);

        return new TtlAssumption(
            strategy: $strategy,
            min: $contract->min,
            max: $contract->max,
            unconstrained: $contract->unconstrained,
            oneOf: $contract->oneOf,
            problems: $contract->problems,
        );
    }

    /**
     * Distinguishes positional alternatives, named bounds, and no constraint.
     *
     * @param array<array-key, mixed> $values
     */
    public function declaration(array $values): TtlContract
    {
        if ($values === []) {
            return new TtlContract(unconstrained: true);
        }

        foreach (array_keys($values) as $key) {
            if (is_string($key)) {
                return $this->range($values);
            }
        }

        return new TtlContract(oneOf: $this->alternatives(array_values($values)));
    }

    /**
     * Reads named bounds while retaining malformed declarations for lint.
     *
     * @param array<array-key, mixed> $values
     */
    public function range(array $values): TtlContract
    {
        $problems = [];

        foreach ($values as $name => $value) {
            if ($name !== 'min' && $name !== 'max') {
                $problems[] = 'use positional lifetime alternatives or named min/max bounds, never both';
            }

            if ($value !== null && !is_int($value) && !$value instanceof ContractReference && !$value instanceof Unresolved) {
                $problems[] = 'a lifetime bound must be integer seconds or an argument reference';
            }
        }

        return new TtlContract(
            min: $this->bound($values['min'] ?? null),
            max: $this->bound($values['max'] ?? null),
            problems: $problems,
        );
    }

    /**
     * Maps declared parameters by name and retains remaining positional arguments.
     *
     * @param array<Arg|\PhpParser\Node\VariadicPlaceholder> $arguments
     * @param list<string> $names
     * @return array<array-key, mixed>
     */
    public function values(array $arguments, array $names): array
    {
        $values = [];
        $position = 0;

        foreach ($arguments as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                $values[] = new TtlContract(problems: ['lifetime arguments cannot use unpacking']);

                continue;
            }

            $name = $argument->name?->toString() ?? $names[$position] ?? $position;

            if ($argument->name === null) {
                ++$position;
            }

            $values[$name] = $this->value($argument->value);
        }

        return $values;
    }

    /**
     * Returns one contract expression as a value, a reference, or unresolved.
     */
    public function value(Expr $expression): mixed
    {
        if ($expression instanceof Array_) {
            return new TtlContract(problems: ['pass lifetime alternatives as separate positional arguments, not an array']);
        }

        if ($expression instanceof New_) {
            if ($expression->class instanceof Name && $expression->class->toString() === TtlRange::class) {
                $values = $this->values($expression->args, ['min', 'max']);

                return $this->range($values);
            }

            return $this->reference($expression) ?? Unresolved::Value;
        }

        $literal = $this->literals->value($expression);

        return $literal === LiteralReader::UNRESOLVED ? Unresolved::Value : $literal;
    }

    /**
     * Reads points and ranges into separate contracts without executing code.
     *
     * @return list<TtlContract>|null
     */
    public function alternatives(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Unresolved) {
            return [new TtlContract(min: Unresolved::Value, max: Unresolved::Value)];
        }

        if (!is_array($value) || !array_is_list($value)) {
            return [new TtlContract(problems: ['lifetime alternatives must be a list'])];
        }

        $alternatives = [];

        foreach ($value as $alternative) {
            if ($alternative instanceof TtlContract) {
                $alternatives[] = $alternative;
            } elseif (is_int($alternative) || $alternative instanceof ContractReference || $alternative instanceof Unresolved) {
                $alternatives[] = new TtlContract(min: $alternative, max: $alternative);
            } else {
                $alternatives[] = new TtlContract(problems: ['a lifetime alternative must be integer seconds, an argument reference, or a TtlRange']);
            }
        }

        return $alternatives;
    }

    /**
     * Returns the explicit reference a new expression declares, when known.
     */
    public function reference(New_ $expression): ?ContractReference
    {
        if (!$expression->class instanceof Name) {
            return null;
        }

        $source = match ($expression->class->toString()) {
            ConstructorArg::class => ContractSource::Constructor,
            ArgReference::class => ContractSource::Create,
            default => null,
        };
        $argument = $expression->args[0] ?? null;

        if ($source === null || !$argument instanceof Arg || !$argument->value instanceof String_) {
            return null;
        }

        return new ContractReference($source, $argument->value->value);
    }

    /**
     * Returns one read value narrowed to what a lifetime bound may be.
     */
    public function bound(mixed $value): int|ContractReference|Unresolved|null
    {
        if ($value === null || is_int($value) || $value instanceof ContractReference || $value instanceof Unresolved) {
            return $value;
        }

        return Unresolved::Value;
    }
}
