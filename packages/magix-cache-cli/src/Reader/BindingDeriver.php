<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;

/**
 * Expands recipe aliases through Deriver while keeping leaves as source nodes.
 *
 * The reader supplies a declaration binding map, not an executable method.
 * Opaque leaf handles retain node identity and source positions and prevent
 * factory calls, constructors or application autoloaders from being executed.
 */
final readonly class BindingDeriver
{
    /**
     * Creates an alias reader sharing the value derivation boundary.
     */
    public function __construct(private ExpressionDeriver $deriver = new ExpressionDeriver())
    {
    }

    /**
     * Resolves the selected alias within the reader's existing expansion budget.
     *
     * @param array<string, Expr> $bindings
     */
    public function bound(Expr $expression, array $bindings, int $budget): Expr
    {
        if ($budget < 1 || !$expression instanceof Variable || !is_string($expression->name)
            || !array_key_exists($expression->name, $bindings)) {
            return $expression;
        }

        $nodes = [$expression, ...array_values($bindings)];
        $indices = array_flip(array_keys($bindings));
        $source = '<?php function resolve() { return binding0('.$budget.'); }';

        foreach ($nodes as $index => $node) {
            $next = $node instanceof Variable && is_string($node->name) ? ($indices[$node->name] ?? null) : null;
            $body = $next === null
                ? 'return '.$index.';'
                : 'if ($remaining < 1) { return '.$index.'; } return binding'.($next + 1).'($remaining - 1);';
            $source .= ' function binding'.$index.'($remaining) { '.$body.' }';
        }

        $resolved = $this->deriver->source($source);

        return is_int($resolved) ? ($nodes[$resolved] ?? $expression) : $expression;
    }
}
