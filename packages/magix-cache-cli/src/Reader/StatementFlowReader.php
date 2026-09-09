<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use Magix\Cache\Cli\Declaration\MetadataFlow;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Follows each return path with its own local bindings, including switch fallthrough.
 */
final readonly class StatementFlowReader
{
    /**
     * Creates a statement reader in the declaring method's type context.
     */
    public function __construct(private ExpressionFlowReader $expressions)
    {
    }

    /**
     * @param list<Stmt> $statements
     * @param array<string, MetadataFlow> $variables
     */
    public function read(array $statements, array $variables = [], int $budget = 8): MetadataFlow
    {
        if ($budget < 1) {
            return new MetadataFlow('unknown');
        }

        foreach ($statements as $position => $statement) {
            if ($statement instanceof Stmt\Return_) {
                return $statement->expr === null ? new MetadataFlow('none') : $this->expressions->read($statement->expr, $variables);
            }

            if ($statement instanceof Stmt\If_ || $statement instanceof Stmt\Switch_) {
                if ($this->conditionWrites($statement)) {
                    return new MetadataFlow('unknown');
                }

                $rest = array_slice($statements, $position + 1);
                $branches = $statement instanceof Stmt\If_ ? $this->branches($statement) : $this->cases($statement);

                return new MetadataFlow('choice', array_map(
                    fn (array $branch): MetadataFlow => $this->read([...$branch, ...$rest], $variables, $budget - 1),
                    $branches,
                ));
            }

            if ($statement instanceof Stmt\Expression) {
                $expression = $statement->expr;

                if ($this->writes($expression instanceof Expr\Assign ? $expression->expr : $expression)) {
                    return new MetadataFlow('unknown');
                }

                if ($expression instanceof Expr\Assign && $expression->var instanceof Expr\Variable && is_string($expression->var->name)) {
                    $variables[$expression->var->name] = $this->expressions->read($expression->expr, $variables);
                } elseif ($expression instanceof Expr\Throw_) {
                    return new MetadataFlow('choice');
                }

                continue;
            }

            if (!$statement instanceof Stmt\Nop) {
                return new MetadataFlow('unknown');
            }
        }

        return new MetadataFlow('none');
    }

    /**
     * Conditions can mutate the bindings used by every continuation.
     */
    public function conditionWrites(Stmt\If_|Stmt\Switch_ $statement): bool
    {
        $conditions = [$statement->cond, ...($statement instanceof Stmt\If_ ? array_map(static fn (Stmt\ElseIf_ $branch): Expr => $branch->cond, $statement->elseifs) : [])];

        foreach ($conditions as $condition) {
            if ($this->writes($condition)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unsupported writes must not reuse a stale local binding.
     */
    public function writes(Expr $expression): bool
    {
        return (new NodeFinder())->findFirst($expression, static fn (Node $node): bool => $node instanceof Expr\Assign
            || $node instanceof Expr\AssignRef || $node instanceof Expr\AssignOp
            || $node instanceof Expr\PreInc || $node instanceof Expr\PostInc
            || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) !== null;
    }

    /**
     * An absent else keeps the continuation reachable with the original bindings.
     *
     * @return list<list<Stmt>>
     */
    public function branches(Stmt\If_ $statement): array
    {
        return [
            array_values($statement->stmts),
            ...array_map(static fn (Stmt\ElseIf_ $branch): array => array_values($branch->stmts), array_values($statement->elseifs)),
            array_values($statement->else->stmts ?? []),
        ];
    }

    /**
     * Each possible entry runs following cases until a break or return.
     *
     * @return list<list<Stmt>>
     */
    public function cases(Stmt\Switch_ $statement): array
    {
        $branches = [];
        $default = false;

        foreach ($statement->cases as $position => $case) {
            $default = $default || $case->cond === null;
            $branch = [];

            foreach (array_slice($statement->cases, $position) as $following) {
                foreach ($following->stmts as $step) {
                    if ($step instanceof Stmt\Break_ && ($step->num === null || ($step->num instanceof Node\Scalar\Int_ && $step->num->value === 1))) {
                        break 2;
                    }

                    $branch[] = $step;
                }
            }

            $branches[] = $branch;
        }

        if (!$default) {
            $branches[] = [];
        }

        return $branches;
    }
}
