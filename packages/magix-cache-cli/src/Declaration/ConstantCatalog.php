<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

use function array_key_exists;
use function array_shift;

use PhpParser\Node\Expr;

/**
 * Holds the constant expressions a declaration may reference.
 *
 * PHP restricts attribute arguments to constant expressions, so every value a
 * cache policy declares is statically decidable. What a single file cannot see
 * is the expression behind a constant declared elsewhere; this catalog supplies
 * it, so an unreadable declaration is a missing source, never a missing rule.
 */
final readonly class ConstantCatalog
{
    /**
     * Creates a catalog of constants collected from scanned sources.
     *
     * @param array<string, array<string, Expr>> $constants Constant expressions by declaring class, then name.
     * @param array<string, list<string>> $parents Direct parents and interfaces, so inherited constants resolve.
     * @param array<string, Expr> $globals Namespaced constant expressions by fully qualified name.
     */
    public function __construct(
        private array $constants = [],
        private array $parents = [],
        private array $globals = [],
    ) {
    }

    /**
     * Returns a catalog holding the constants of both sources.
     */
    public function merge(self $other): self
    {
        return new self(
            $this->constants + $other->constants,
            $this->parents + $other->parents,
            $this->globals + $other->globals,
        );
    }

    /**
     * Returns the expression a class constant is declared with, following inheritance.
     *
     * Parents and interfaces are searched breadth first, which is the order
     * PHP itself resolves an inherited constant in.
     */
    public function classConstant(string $class, string $name): ?Expr
    {
        $queue = [$class];
        $seen = [];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (array_key_exists($current, $seen)) {
                continue;
            }

            $seen[$current] = true;

            if (isset($this->constants[$current][$name])) {
                return $this->constants[$current][$name];
            }

            foreach ($this->parents[$current] ?? [] as $parent) {
                $queue[] = $parent;
            }
        }

        return null;
    }

    /**
     * Returns the expression a namespaced constant is declared with.
     */
    public function globalConstant(string $name): ?Expr
    {
        return $this->globals[$name] ?? null;
    }
}
