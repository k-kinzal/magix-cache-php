<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use Deriver\Analyzer;
use Deriver\Project\Configuration;
use Deriver\Project\EntryPoint;
use Deriver\Project\ProjectInput;
use Deriver\Project\SourceFile;
use Deriver\Project\TargetProfile;
use Deriver\Query\QueryScope;
use Deriver\Query\ReturnQuery;
use Deriver\Value\Term;
use JsonException;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\PrettyPrinter\Standard;

/**
 * Derives values in an explicit source world without running application code.
 *
 * Only an exhaustive, exact, concrete normal result can become a declaration
 * value. A concrete value on one possible path is insufficient proof.
 */
final readonly class ExpressionDeriver
{
    /**
     * Shares Deriver's bounded source caches across expressions read together.
     */
    public function __construct(private Analyzer $analyzer = new Analyzer())
    {
    }

    /**
     * Evaluates one admitted expression with explicit scalar inputs.
     *
     * @param array<string, int|float|string|bool|null> $bindings
     * @return mixed Concrete value, or LiteralReader::UNRESOLVED when exhaustive derivation is unavailable.
     */
    public function value(Expr $expression, array $bindings = []): mixed
    {
        $parameters = array_map(static fn (string $name): Param => new Param(new Variable($name)), array_keys($bindings));
        $source = (new Standard())->prettyPrintFile([new Function_('resolve', [
            'params' => $parameters,
            'stmts' => [new Return_($expression)],
        ])]);

        return $this->source($source, array_map(Term::constant(...), array_values($bindings)));
    }

    /**
     * Reads a concrete return from generated, isolated analysis source.
     *
     * @param list<Term> $arguments Explicit inputs; user parameters are never inferred from defaults.
     * @return mixed Concrete value, or LiteralReader::UNRESOLVED for partial, exceptional or non-concrete results.
     */
    public function source(string $source, array $arguments = []): mixed
    {
        try {
            $session = $this->analyzer->open(
                new ProjectInput([new SourceFile('expression.php', $source)]),
                new Configuration(new TargetProfile(floatPrecision: (int) ini_get('precision'))),
            );
        } catch (JsonException) {
            return LiteralReader::UNRESOLVED;
        }

        $result = $session->derive(new ReturnQuery('resolve', QueryScope::fromEntrypoints([
            new EntryPoint('resolve', $arguments),
        ])));

        if ($result->assessment->closure !== 'closed'
            || $result->assessment->precision !== 'exact-symbolic'
            || $result->assessment->correlation !== 'preserved'
            || $result->assessment->enumeration !== 'finite-exhaustive'
            || $result->assessment->coverage !== 'over-approximation') {
            return LiteralReader::UNRESOLVED;
        }

        $value = $result->definite()?->values['return'] ?? null;

        return $value !== null ? $value->native() : LiteralReader::UNRESOLVED;
    }
}
