<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function array_slice;
use function array_values;
use function in_array;

use Magix\Cache\Cli\Declaration\MetadataFlow;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Follows each return path with its own local bindings.
 *
 * A statement this reader does not model cannot decide which value is
 * returned unless it returns one: everything else can only rebind variables.
 * Such a statement therefore drops the bindings it writes and the walk goes
 * on, instead of abandoning the whole method. Only statements that can return
 * fork the walk into alternatives.
 */
final readonly class StatementFlowReader
{
    /**
     * Creates a statement reader in the declaring method's type context.
     */
    public function __construct(
        private ExpressionFlowReader $expressions,
        private VariableEffects $variables = new VariableEffects(),
    ) {
    }

    /**
     * @param list<Stmt> $statements
     * @param array<string, MetadataFlow> $variables
     * @param int $budget Remaining forks, bounding the alternatives one body may produce.
     */
    public function read(array $statements, array $variables = [], int $budget = 24): MetadataFlow
    {
        if ($budget < 1) {
            return MetadataFlow::unknown('control-flow-limit', ($statements[0] ?? null)?->getStartLine() ?? 0);
        }

        foreach ($statements as $position => $statement) {
            if ($statement instanceof Stmt\Return_) {
                return $statement->expr === null ? new MetadataFlow('none') : $this->expressions->read($statement->expr, $variables);
            }

            if ($this->variables->aliases($statement)) {
                return MetadataFlow::unknown('aliased-binding', $statement->getStartLine());
            }

            if ($statement instanceof Stmt\Expression) {
                $variables = $this->expression($statement->expr, $variables);

                if ($statement->expr instanceof Expr\Throw_) {
                    return new MetadataFlow('choice');
                }

                continue;
            }

            $rest = array_slice($statements, $position + 1);

            if (!$this->affects($statement, $rest)) {
                $variables = $this->variables->unbind($statement, $variables);

                continue;
            }

            return $this->fork($statement, $rest, $variables, $budget);
        }

        return new MetadataFlow('none');
    }

    /**
     * Reports whether a statement can change the value this method returns.
     *
     * A statement that neither returns nor writes anything a later statement
     * reads cannot decide the result, however unmodelled its syntax is.
     *
     * @param list<Stmt> $rest
     */
    public function affects(Stmt $statement, array $rest): bool
    {
        if ($this->returns($statement)) {
            return true;
        }

        $written = $this->variables->written($statement);

        if ($written === []) {
            return false;
        }

        foreach ($rest as $following) {
            foreach ($this->variables->reads($following) as $name) {
                if (in_array($name, $written, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns the alternatives a returning statement opens, or an unread body.
     *
     * @param list<Stmt> $rest
     * @param array<string, MetadataFlow> $variables
     */
    public function fork(Stmt $statement, array $rest, array $variables, int $budget): MetadataFlow
    {
        $branches = $this->branches($statement);

        if ($branches === null || $this->conditionWrites($statement)) {
            return MetadataFlow::unknown('unsupported-control-flow', $statement->getStartLine());
        }

        return new MetadataFlow('choice', array_map(
            fn (array $branch): MetadataFlow => $this->read([...$branch, ...$rest], $variables, $budget - 1),
            $branches,
        ));
    }

    /**
     * Returns the continuations of a statement that can return, or null.
     *
     * A loop body is one alternative next to skipping it, because the number
     * of iterations is not decided here.
     *
     * @return list<list<Stmt>>|null
     */
    public function branches(Stmt $statement): ?array
    {
        if ($statement instanceof Stmt\If_) {
            return [
                array_values($statement->stmts),
                ...array_map(static fn (Stmt\ElseIf_ $branch): array => array_values($branch->stmts), array_values($statement->elseifs)),
                array_values($statement->else->stmts ?? []),
            ];
        }

        if ($statement instanceof Stmt\Switch_) {
            return $this->cases($statement);
        }

        if ($statement instanceof Stmt\TryCatch) {
            $finally = $statement->finally;

            if ($finally !== null && $this->returns($finally)) {
                return [array_values($finally->stmts)];
            }

            return array_values([
                array_values($statement->stmts),
                ...array_map(static fn (Stmt\Catch_ $catch): array => array_values($catch->stmts), $statement->catches),
            ]);
        }

        if ($statement instanceof Stmt\Foreach_ || $statement instanceof Stmt\While_
            || $statement instanceof Stmt\Do_ || $statement instanceof Stmt\For_) {
            return [array_values($statement->stmts), []];
        }

        return null;
    }

    /**
     * Reports whether a statement can return from the declaring method.
     *
     * A return inside a nested function belongs to that function, not here.
     */
    public function returns(Node $node): bool
    {
        $nested = [];
        $found = [];

        foreach ((new NodeFinder())->find($node, static fn (Node $item): bool => $item instanceof Stmt\Return_
            || $item instanceof FunctionLike || $item instanceof Stmt\ClassLike) as $item) {
            if ($item instanceof Stmt\Return_) {
                $found[] = $item;

                continue;
            }

            $nested[] = $item;
        }

        foreach ($found as $return) {
            if (!$this->inside($return, $nested)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a node lies inside one of the given nested scopes.
     *
     * @param list<Node> $scopes
     */
    public function inside(Node $node, array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($scope->getStartFilePos() <= $node->getStartFilePos() && $node->getEndFilePos() <= $scope->getEndFilePos()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the bindings that survive one expression statement.
     *
     * @param array<string, MetadataFlow> $variables
     * @return array<string, MetadataFlow>
     */
    public function expression(Expr $expression, array $variables): array
    {
        if (!$expression instanceof Expr\Assign || $this->variables->writes($expression->expr)) {
            return $this->variables->unbind($expression, $variables);
        }

        $target = $expression->var;

        if ($target instanceof Expr\Variable && is_string($target->name)) {
            $variables[$target->name] = $this->expressions->read($expression->expr, $variables);

            return $variables;
        }

        $root = $target instanceof Expr\ArrayDimFetch ? $this->variables->root($target) : null;

        if ($root === null) {
            return $this->variables->unbind($expression, $variables);
        }

        $variables[$root] = $this->variables->extend($variables[$root] ?? null, $this->expressions->read($expression->expr, $variables));

        return $variables;
    }

    /**
     * Conditions can mutate the bindings every continuation reads.
     */
    public function conditionWrites(Stmt $statement): bool
    {
        $conditions = match (true) {
            $statement instanceof Stmt\If_ => [$statement->cond, ...array_map(static fn (Stmt\ElseIf_ $branch): Expr => $branch->cond, $statement->elseifs)],
            $statement instanceof Stmt\Switch_ => [$statement->cond],
            default => [],
        };

        foreach ($conditions as $condition) {
            if ($this->variables->writes($condition)) {
                return true;
            }
        }

        return false;
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
