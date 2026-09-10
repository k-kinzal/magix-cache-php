<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Source;

use Magix\Cache\Cli\Declaration\ConstantCatalog;
use Override;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects the constant expressions one file declares.
 *
 * Constants are gathered before any declaration is read, because a policy in
 * one file may reference a constant declared in another.
 */
final class ConstantVisitor extends NodeVisitorAbstract
{
    /**
     * Constant expressions by declaring class, then name.
     *
     * @var array<string, array<string, Node\Expr>>
     */
    private array $constants = [];

    /**
     * Direct parents and interfaces by class.
     *
     * @var array<string, list<string>>
     */
    private array $parents = [];

    /**
     * Namespaced constant expressions by fully qualified name.
     *
     * @var array<string, Node\Expr>
     */
    private array $globals = [];

    /**
     * Collects the constants of every class-like and namespaced declaration.
     */
    #[Override]
    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Const_) {
            foreach ($node->consts as $constant) {
                $this->globals[$constant->namespacedName?->toString() ?? $constant->name->toString()] = $constant->value;
            }

            return null;
        }

        if (!$node instanceof ClassLike || $node->name === null) {
            return null;
        }

        $name = $node->namespacedName?->toString() ?? $node->name->toString();
        $this->parents[$name] = $this->ancestors($node);

        foreach ($node->stmts as $statement) {
            if ($statement instanceof ClassConst) {
                foreach ($statement->consts as $constant) {
                    $this->constants[$name][$constant->name->toString()] = $constant->value;
                }

                continue;
            }

            if ($statement instanceof EnumCase && $statement->expr !== null) {
                $this->constants[$name][$statement->name->toString()] = $statement->expr;
            }
        }

        return null;
    }

    /**
     * Returns the types a class-like declaration inherits constants from.
     *
     * @return list<string>
     */
    public function ancestors(ClassLike $node): array
    {
        $parents = [];

        if ($node instanceof Class_) {
            if ($node->extends !== null) {
                $parents[] = $node->extends->toString();
            }

            foreach ($node->implements as $interface) {
                $parents[] = $interface->toString();
            }
        }

        if ($node instanceof Interface_) {
            foreach ($node->extends as $interface) {
                $parents[] = $interface->toString();
            }
        }

        if ($node instanceof Enum_) {
            foreach ($node->implements as $interface) {
                $parents[] = $interface->toString();
            }
        }

        return $parents;
    }

    /**
     * Returns everything collected from the traversed file.
     */
    public function catalog(): ConstantCatalog
    {
        return new ConstantCatalog($this->constants, $this->parents, $this->globals);
    }
}
