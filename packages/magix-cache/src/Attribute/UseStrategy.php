<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;
use InvalidArgumentException;

use function is_callable;

use LogicException;
use Magix\Cache\Strategy\StrategyDefinition;

use function method_exists;

/**
 * Declares which strategy composition a boundary runs, and with which values.
 *
 * The attribute is the declarative entry to a strategy, not a runtime of its
 * own: it names a class with a public static create() and carries the typed
 * arguments to pass to it. The resolver memoizes the construction definition
 * returned by create(), never an executable strategy. Every invocation
 * constructs fresh strategy instances from that definition. The analyzer
 * binds the same arguments to the definition without executing user code.
 * A method-level declaration replaces a class-level one as a whole, and
 * enabled: false disables a class-level default.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class UseStrategy
{
    /**
     * Arguments forwarded to the create() of the strategy class.
     *
     * @var array<array-key, mixed>
     */
    public array $arguments;

    /**
     * Declares the strategy of a boundary.
     *
     * @param string $strategy Class name whose static create() describes the strategy.
     * @param bool $enabled Explicit false disables a class-level declaration.
     * @param mixed ...$arguments Arguments forwarded to create() by position or name.
     * @throws InvalidArgumentException when the strategy reference is empty
     */
    public function __construct(
        public string $strategy,
        public bool $enabled = true,
        mixed ...$arguments,
    ) {
        if ($strategy === '') {
            throw new InvalidArgumentException('UseStrategy must name a strategy class.');
        }

        $this->arguments = $arguments;
    }

    /**
     * Resolves an immutable construction definition through create().
     *
     * @throws LogicException when the class has no static create() or it returns no StrategyDefinition
     */
    public function resolve(): StrategyDefinition
    {
        $factory = [$this->strategy, 'create'];

        if (!method_exists($this->strategy, 'create') || !is_callable($factory)) {
            throw new LogicException($this->strategy.' declares no public static create() that describes its strategy.');
        }

        $strategy = $factory(...$this->arguments);

        if (!$strategy instanceof StrategyDefinition) {
            throw new LogicException($this->strategy.'::create() must return a StrategyDefinition.');
        }

        return $strategy;
    }
}
