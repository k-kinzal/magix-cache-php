<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function is_float;
use function is_int;

use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

use function strtolower;

/**
 * Reads the constant expressions MagixCache attributes are written with.
 */
final readonly class LiteralReader
{
    /**
     * Returned when an expression cannot be read without executing code.
     */
    public const string UNRESOLVED = "\0magix-unresolved";

    /**
     * Enum cases that may appear in a cache policy declaration.
     */
    private const array CASES = [
        Ttl::class.'::Auto' => Ttl::Auto,
        Ttl::class.'::FromUpstream' => Ttl::FromUpstream,
        Visibility::class.'::Shared' => Visibility::Shared,
        Visibility::class.'::Private' => Visibility::Private,
        Visibility::class.'::NoStore' => Visibility::NoStore,
    ];

    /**
     * Returns the value of a constant expression, or the unresolved marker.
     */
    public function value(Expr $expression): mixed
    {
        if ($expression instanceof Int_ || $expression instanceof Float_ || $expression instanceof String_) {
            return $expression->value;
        }

        if ($expression instanceof UnaryMinus) {
            $value = $this->value($expression->expr);

            return is_int($value) || is_float($value) ? -$value : self::UNRESOLVED;
        }

        if ($expression instanceof ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => self::UNRESOLVED,
            };
        }

        if ($expression instanceof ClassConstFetch) {
            return $this->constant($expression);
        }

        return $expression instanceof Array_ ? $this->items($expression) : self::UNRESOLVED;
    }

    /**
     * Returns the value of a class constant that a policy may reference.
     */
    public function constant(ClassConstFetch $expression): mixed
    {
        if (!$expression->class instanceof Name || !$expression->name instanceof Identifier) {
            return self::UNRESOLVED;
        }

        $name = $expression->name->toString();

        if ($name === 'class') {
            return $expression->class->toString();
        }

        return self::CASES[$expression->class->toString().'::'.$name] ?? self::UNRESOLVED;
    }

    /**
     * Returns the values of an array literal, or the unresolved marker.
     *
     * @return array<array-key, mixed>|string
     */
    public function items(Array_ $expression): array|string
    {
        $values = [];

        foreach ($expression->items as $item) {
            $value = $this->value($item->value);

            if ($value === self::UNRESOLVED) {
                return self::UNRESOLVED;
            }

            $key = $item->key === null ? null : $this->value($item->key);

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
