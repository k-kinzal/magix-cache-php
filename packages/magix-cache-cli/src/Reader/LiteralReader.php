<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function array_key_exists;
use function constant;
use function defined;
use function is_float;
use function is_int;
use function is_string;

use Magix\Cache\Cli\Declaration\ConstantCatalog;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

use function str_starts_with;

/**
 * Reads the constant expressions MagixCache attributes are written with.
 *
 * PHP restricts attribute arguments to constant expressions, so this reader
 * can decide every declared value given the sources the constants live in.
 * Anything it returns as unresolved is a missing source or an expression PHP
 * itself would reject, never a value the reader chose not to compute.
 */
final readonly class LiteralReader
{
    /**
     * Returned when an expression cannot be read without executing code.
     */
    public const string UNRESOLVED = "\0magix-unresolved";

    /**
     * Namespace whose enums this tool already depends on and may reflect on.
     */
    private const string LIBRARY = 'Magix\\Cache\\';

    /**
     * Creates a literal reader over the constants of the scanned sources.
     */
    public function __construct(private ConstantCatalog $constants = new ConstantCatalog())
    {
    }

    /**
     * Returns the value of a constant expression, or the unresolved marker.
     *
     * @param array<string, true> $seen Constants already being resolved on this path.
     */
    public function value(Expr $expression, array $seen = []): mixed
    {
        if ($expression instanceof Int_ || $expression instanceof Float_ || $expression instanceof String_) {
            return $expression->value;
        }

        if ($expression instanceof UnaryMinus || $expression instanceof UnaryPlus) {
            $value = $this->value($expression->expr, $seen);

            if (!is_int($value) && !is_float($value)) {
                return self::UNRESOLVED;
            }

            return $expression instanceof UnaryMinus ? -$value : $value;
        }

        if ($expression instanceof ConstFetch) {
            return $this->globalConstant($expression, $seen);
        }

        if ($expression instanceof ClassConstFetch) {
            return $this->constant($expression, $seen);
        }

        if ($expression instanceof BinaryOp) {
            return $this->binary($expression, $seen);
        }

        return $expression instanceof Array_ ? $this->items($expression, $seen) : self::UNRESOLVED;
    }

    /**
     * Folds the binary operators PHP allows in a constant expression.
     *
     * @param array<string, true> $seen
     */
    public function binary(BinaryOp $expression, array $seen = []): mixed
    {
        $left = $this->value($expression->left, $seen);
        $right = $this->value($expression->right, $seen);

        if ($left === self::UNRESOLVED || $right === self::UNRESOLVED) {
            return self::UNRESOLVED;
        }

        if ($expression instanceof BinaryOp\Concat) {
            return $this->concatenate($left, $right);
        }

        if ((!is_int($left) && !is_float($left)) || (!is_int($right) && !is_float($right))) {
            return self::UNRESOLVED;
        }

        return match (true) {
            $expression instanceof BinaryOp\Plus => $left + $right,
            $expression instanceof BinaryOp\Minus => $left - $right,
            $expression instanceof BinaryOp\Mul => $left * $right,
            $expression instanceof BinaryOp\Pow => $left ** $right,
            $expression instanceof BinaryOp\Div => $right === 0 || $right === 0.0 ? self::UNRESOLVED : $left / $right,
            default => $this->integerOperator($expression, $left, $right),
        };
    }

    /**
     * Joins two scalars the way a constant expression concatenates them.
     */
    public function concatenate(mixed $left, mixed $right): string
    {
        if ((!is_string($left) && !is_int($left) && !is_float($left))
            || (!is_string($right) && !is_int($right) && !is_float($right))) {
            return self::UNRESOLVED;
        }

        return $left.$right;
    }

    /**
     * Applies the operators PHP defines on integers only.
     *
     * A float operand is not widened into an integer: the declaration would
     * not mean what the folded value says.
     */
    public function integerOperator(BinaryOp $expression, int|float $left, int|float $right): int|string
    {
        if (!is_int($left) || !is_int($right)) {
            return self::UNRESOLVED;
        }

        return match (true) {
            $expression instanceof BinaryOp\Mod => $right === 0 ? self::UNRESOLVED : $left % $right,
            $expression instanceof BinaryOp\BitwiseAnd => $left & $right,
            $expression instanceof BinaryOp\BitwiseOr => $left | $right,
            $expression instanceof BinaryOp\BitwiseXor => $left ^ $right,
            $expression instanceof BinaryOp\ShiftLeft => $right < 0 ? self::UNRESOLVED : $left << $right,
            $expression instanceof BinaryOp\ShiftRight => $right < 0 ? self::UNRESOLVED : $left >> $right,
            default => self::UNRESOLVED,
        };
    }

    /**
     * Returns the value of a namespaced or built-in constant.
     *
     * @param array<string, true> $seen
     */
    public function globalConstant(ConstFetch $expression, array $seen = []): mixed
    {
        $name = $expression->name->toString();

        $literal = match (strtolower($name)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => self::UNRESOLVED,
        };

        if ($literal !== self::UNRESOLVED) {
            return $literal;
        }

        $declared = $this->constants->globalConstant($name);

        if ($declared !== null) {
            return $this->follow('const '.$name, $declared, $seen);
        }

        return defined($name) ? constant($name) : self::UNRESOLVED;
    }

    /**
     * Returns the value of a class constant or enum case.
     *
     * @param array<string, true> $seen
     */
    public function constant(ClassConstFetch $expression, array $seen = []): mixed
    {
        if (!$expression->class instanceof Name || !$expression->name instanceof Identifier) {
            return self::UNRESOLVED;
        }

        $class = $expression->class->toString();
        $name = $expression->name->toString();

        if ($name === 'class') {
            return $class;
        }

        $declared = $this->constants->classConstant($class, $name);

        if ($declared !== null) {
            return $this->follow($class.'::'.$name, $declared, $seen);
        }

        return str_starts_with($class, self::LIBRARY) && defined($class.'::'.$name)
            ? constant($class.'::'.$name)
            : self::UNRESOLVED;
    }

    /**
     * Resolves a referenced constant unless it is already being resolved.
     *
     * @param array<string, true> $seen
     */
    public function follow(string $reference, Expr $expression, array $seen = []): mixed
    {
        if (array_key_exists($reference, $seen)) {
            return self::UNRESOLVED;
        }

        return $this->value($expression, [...$seen, $reference => true]);
    }

    /**
     * Returns the values of an array literal, or the unresolved marker.
     *
     * @param array<string, true> $seen
     * @return array<array-key, mixed>|string
     */
    public function items(Array_ $expression, array $seen = []): array|string
    {
        $values = [];

        foreach ($expression->items as $item) {
            if ($item->unpack) {
                return self::UNRESOLVED;
            }

            $value = $this->value($item->value, $seen);

            if ($value === self::UNRESOLVED) {
                return self::UNRESOLVED;
            }

            $key = $item->key === null ? null : $this->value($item->key, $seen);

            if ($key === null) {
                $values[] = $value;

                continue;
            }

            if (!is_int($key) && !is_string($key)) {
                return self::UNRESOLVED;
            }

            $values[$key] = $value;
        }

        return $values;
    }
}
