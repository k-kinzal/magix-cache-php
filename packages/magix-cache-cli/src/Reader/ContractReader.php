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
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
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
     * Parameter order of the Contract\Ttl attribute.
     */
    private const array TTL_OPTIONS = ['min', 'max', 'unconstrained'];

    /**
     * Parameter order of the Contract\AssumeTtl attribute.
     */
    private const array ASSUME_OPTIONS = ['strategy', 'min', 'max', 'unconstrained'];

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
        $values = $this->values($attribute->args, self::TTL_OPTIONS);

        return new TtlContract(
            min: $this->bound($values['min'] ?? null),
            max: $this->bound($values['max'] ?? null),
            unconstrained: ($values['unconstrained'] ?? false) === true,
        );
    }

    /**
     * Returns the assumption one attribute declares, when it names a strategy.
     */
    public function assumption(Attribute $attribute): ?TtlAssumption
    {
        $values = $this->values($attribute->args, self::ASSUME_OPTIONS);
        $strategy = $values['strategy'] ?? null;

        if (!is_string($strategy) || $strategy === '') {
            return null;
        }

        return new TtlAssumption(
            strategy: $strategy,
            min: $this->bound($values['min'] ?? null),
            max: $this->bound($values['max'] ?? null),
            unconstrained: ($values['unconstrained'] ?? false) === true,
        );
    }

    /**
     * Returns the value written for each named or positional parameter.
     *
     * @param array<Arg|\PhpParser\Node\VariadicPlaceholder> $arguments
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

            $name = $argument->name?->toString() ?? $names[$position] ?? null;

            if ($argument->name === null) {
                ++$position;
            }

            if ($name !== null) {
                $values[$name] = $this->value($argument->value);
            }
        }

        return $values;
    }

    /**
     * Returns one contract expression as a value, a reference, or unresolved.
     */
    public function value(Expr $expression): mixed
    {
        if ($expression instanceof New_) {
            return $this->reference($expression) ?? Unresolved::Value;
        }

        $literal = $this->literals->value($expression);

        return $literal === LiteralReader::UNRESOLVED ? Unresolved::Value : $literal;
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
