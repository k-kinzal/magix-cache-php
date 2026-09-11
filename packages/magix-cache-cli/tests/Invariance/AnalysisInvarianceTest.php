<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Invariance;

use Magix\Cache\Cli\Graph\CacheTree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\Invariance;

/**
 * States the property the analyzer must hold: rewriting code without changing
 * what it computes must not change what the analyzer reports.
 *
 * Every case pairs two forms of the same computation. The pair form avoids
 * pinning values that may legitimately evolve, and it fails loudly when a
 * local analysis limit leaks into an unrelated field or an unrelated node.
 */
#[CoversClass(CacheTree::class)]
#[UsesNamespace('Magix\Cache\Cli')]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesClass(\Magix\Cache\Strategy\Contract\Ttl::class)]
#[UsesClass(\Magix\Cache\Strategy\Contract\ConstructorArg::class)]
final class AnalysisInvarianceTest extends TestCase
{
    /**
     * The dependency and helpers every case composes.
     */
    private const string COMMON = <<<'PHP'
        class Consts { public const int TTL = 300; public const string VERSION = 'v7'; }
        class Child { use Cacheable;
            #[Cache(ttl: 20, tags: ['product'])]
            public function execute(): Cached { return $this->cached(fn () => Cached::of(1)); }
        }
        class Other { use Cacheable;
            #[Cache(ttl: 999, tags: ['other'])]
            public function execute(): Cached { return $this->cached(fn () => Cached::of(2)); }
        }
        PHP;

    /**
     * Naming a subexpression is not a change in what the code computes.
     */
    public function testExtractingAnExpressionIntoALocalVariableKeepsTheResult(): void
    {
        $direct = <<<'PHP'
            class RootA { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(): Cached { return $this->cached(fn () => Cached::of($this->c->execute())->value()); }
            }
            PHP;
        $variable = <<<'PHP'
            class RootB { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(): Cached {
                    return $this->cached(function () {
                        $box = Cached::of($this->c->execute());

                        return $box->value();
                    });
                }
            }
            PHP;

        $this->assertRewriteKeeps(
            '20s',
            Invariance::summarize(Invariance::source(self::COMMON.$direct), 'RootA::run'),
            Invariance::summarize(Invariance::source(self::COMMON.$variable), 'RootB::run'),
        );
    }

    /**
     * Branches that never touch the returned value cannot widen it.
     */
    public function testStatementsThatCannotAffectTheResultKeepTheResult(): void
    {
        $plain = <<<'PHP'
            class RootA { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(int $n): Cached { return $this->cached(fn () => $this->c->execute()); }
            }
            PHP;
        $guarded = <<<'PHP'
            class RootB { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(int $n): Cached {
                    return $this->cached(function () use ($n) {
                        if ($n === 1) { $a = 1; }
                        if ($n === 2) { $a = 2; }
                        if ($n === 3) { $a = 3; }
                        if ($n === 4) { $a = 4; }
                        if ($n === 5) { $a = 5; }
                        if ($n === 6) { $a = 6; }
                        if ($n === 7) { $a = 7; }
                        if ($n === 8) { $a = 8; }
                        if ($n === 9) { $a = 9; }

                        return $this->c->execute();
                    });
                }
            }
            PHP;

        $this->assertRewriteKeeps(
            '20s',
            Invariance::summarize(Invariance::source(self::COMMON.$plain), 'RootA::run'),
            Invariance::summarize(Invariance::source(self::COMMON.$guarded), 'RootB::run'),
        );
    }

    /**
     * A loop over unrelated data cannot widen the returned metadata.
     */
    public function testALoopThatNeverTouchesTheResultKeepsTheResult(): void
    {
        $plain = <<<'PHP'
            class RootA { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(): Cached { return $this->cached(fn () => $this->c->execute()); }
            }
            PHP;
        $looped = <<<'PHP'
            class RootB { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(): Cached {
                    return $this->cached(function () {
                        $result = $this->c->execute();

                        foreach ([1, 2, 3] as $item) {
                            $seen = $item;
                        }

                        return $result;
                    });
                }
            }
            PHP;

        $this->assertRewriteKeeps(
            '20s',
            Invariance::summarize(Invariance::source(self::COMMON.$plain), 'RootA::run'),
            Invariance::summarize(Invariance::source(self::COMMON.$looped), 'RootB::run'),
        );
    }

    /**
     * Alternatives that agree on the result leave the result unchanged.
     */
    public function testBranchesThatReturnTheSameDependencyKeepTheResult(): void
    {
        $plain = <<<'PHP'
            class RootA { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(): Cached { return $this->cached(fn () => $this->c->execute()); }
            }
            PHP;
        $guarded = <<<'PHP'
            class RootB { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(): Cached {
                    return $this->cached(function () {
                        try {
                            return $this->c->execute();
                        } catch (\RuntimeException) {
                            return $this->c->execute();
                        }
                    });
                }
            }
            PHP;

        $this->assertRewriteKeeps(
            '20s',
            Invariance::summarize(Invariance::source(self::COMMON.$plain), 'RootA::run'),
            Invariance::summarize(Invariance::source(self::COMMON.$guarded), 'RootB::run'),
        );
    }

    /**
     * A later assignment cannot decide which method an earlier call reached.
     */
    public function testRebindingAVariableAfterTheCallKeepsTheOriginalCallee(): void
    {
        $plain = <<<'PHP'
            class RootA { use Cacheable;
                #[Cache]
                public function run(): Cached {
                    return $this->cached(function () {
                        $q = new Child();

                        return $q->execute();
                    });
                }
            }
            PHP;
        $rebound = <<<'PHP'
            class RootB { use Cacheable;
                #[Cache]
                public function run(): Cached {
                    return $this->cached(function () {
                        $q = new Child();
                        $first = $q->execute();
                        $q = new Other();

                        return $first;
                    });
                }
            }
            PHP;

        $this->assertRewriteKeeps(
            '20s',
            Invariance::summarize(Invariance::source(self::COMMON.$plain), 'RootA::run'),
            Invariance::summarize(Invariance::source(self::COMMON.$rebound), 'RootB::run'),
        );
    }

    /**
     * PHP restricts attribute arguments to constant expressions, so both forms are decidable.
     */
    public function testConstantAttributeArgumentsReadTheSameAsLiterals(): void
    {
        $literal = <<<'PHP'
            class RootA { use Cacheable;
                #[Cache(ttl: 300, version: 'v7')]
                public function run(): Cached { return $this->cached(fn () => Cached::of(1)); }
            }
            PHP;
        $constant = <<<'PHP'
            class RootB { use Cacheable;
                #[Cache(ttl: Consts::TTL, version: Consts::VERSION)]
                public function run(): Cached { return $this->cached(fn () => Cached::of(1)); }
            }
            PHP;

        $this->assertRewriteKeeps(
            '300s',
            Invariance::summarize(Invariance::source(self::COMMON.$literal), 'RootA::run'),
            Invariance::summarize(Invariance::source(self::COMMON.$constant), 'RootB::run'),
        );
    }

    /**
     * Extracting a helper does not detach the metadata it returns.
     */
    public function testMovingWorkIntoAHelperMethodKeepsTheResult(): void
    {
        $inline = <<<'PHP'
            class RootA { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(): Cached { return $this->cached(fn () => $this->c->execute()); }
            }
            PHP;
        $helper = <<<'PHP'
            class RootB { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(): Cached { return $this->cached(fn () => $this->helper()); }
                private function helper(): Cached { return $this->c->execute(); }
            }
            PHP;

        $this->assertRewriteKeeps(
            '20s',
            Invariance::summarize(Invariance::source(self::COMMON.$inline), 'RootA::run'),
            Invariance::summarize(Invariance::source(self::COMMON.$helper), 'RootB::run'),
        );
    }

    /**
     * Wrapping a Cached value and taking it back out is the identity.
     */
    public function testWrappingAndUnwrappingACachedValueKeepsTheResult(): void
    {
        $plain = <<<'PHP'
            class RootA { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(): Cached { return $this->cached(fn () => $this->c->execute()); }
            }
            PHP;
        $nested = <<<'PHP'
            class RootB { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(): Cached { return $this->cached(fn () => Cached::of($this->c->execute())->value()); }
            }
            PHP;

        $this->assertRewriteKeeps(
            '20s',
            Invariance::summarize(Invariance::source(self::COMMON.$plain), 'RootA::run'),
            Invariance::summarize(Invariance::source(self::COMMON.$nested), 'RootB::run'),
        );
    }

    /**
     * Reaching one boundary by two paths analyzes it once, to the same result.
     */
    public function testReachingABoundaryTwiceAnalyzesItToTheSameResult(): void
    {
        $source = Invariance::source(self::COMMON.<<<'PHP'
            class Middle { use Cacheable;
                public function __construct(private Child $c) {}
                #[Cache]
                public function run(): Cached { return $this->cached(fn () => $this->c->execute()); }
            }
            class RootA { use Cacheable;
                public function __construct(private Middle $m) {}
                #[Cache]
                public function run(): Cached { return $this->cached(fn () => $this->m->run()); }
            }
            class Sibling { use Cacheable;
                public function __construct(private Middle $m) {}
                #[Cache]
                public function run(): Cached { return $this->cached(fn () => $this->m->run()); }
            }
            PHP);

        $this->assertRewriteKeeps(
            '20s',
            Invariance::summarize($source, 'RootA::run'),
            Invariance::summarize($source, 'Sibling::run'),
        );
    }

    /**
     * Naming a definition inside create() does not change the composition it builds.
     */
    public function testALocalVariableInsideCreateKeepsTheStrategyComposition(): void
    {
        $leaf = <<<'PHP'
            class Window implements CacheStrategy {
                public function __construct(private int $minimum) {}
                public function get($operation, $next): ?\Magix\Cache\Strategy\CacheRead { return $next->get($operation); }
                #[\Magix\Cache\Strategy\Contract\Ttl(min: new \Magix\Cache\Strategy\Contract\ConstructorArg('minimum'), max: new \Magix\Cache\Strategy\Contract\ConstructorArg('minimum'))]
                public function fetch($operation, $next) { return $next->fetch($operation); }
                public function set($operation, $result, $next): void { $next->set($operation, $result); }
            }
            PHP;
        $direct = <<<'PHP'
            class DirectWindow implements CacheStrategy {
                public function __construct(private int $minimum) {}
                public static function create(int $minimum): StrategyDefinition { return StrategyDefinition::of(Window::class, $minimum); }
                public function get($operation, $next): ?\Magix\Cache\Strategy\CacheRead { return $next->get($operation); }
                public function fetch($operation, $next) { return $next->fetch($operation); }
                public function set($operation, $result, $next): void { $next->set($operation, $result); }
            }
            class RootA { use Cacheable;
                #[Cache]
                #[UseStrategy(DirectWindow::class, minimum: 45)]
                public function run(): Cached { return $this->cached(fn () => Cached::of(1)); }
            }
            PHP;
        $variable = <<<'PHP'
            class VariableWindow implements CacheStrategy {
                public function __construct(private int $minimum) {}
                public static function create(int $minimum): StrategyDefinition {
                    $definition = StrategyDefinition::of(Window::class, $minimum);

                    return $definition;
                }
                public function get($operation, $next): ?\Magix\Cache\Strategy\CacheRead { return $next->get($operation); }
                public function fetch($operation, $next) { return $next->fetch($operation); }
                public function set($operation, $result, $next): void { $next->set($operation, $result); }
            }
            class RootB { use Cacheable;
                #[Cache]
                #[UseStrategy(VariableWindow::class, minimum: 45)]
                public function run(): Cached { return $this->cached(fn () => Cached::of(1)); }
            }
            PHP;

        $this->assertRewriteKeeps(
            '45s',
            Invariance::summarize(Invariance::source($leaf.$direct), 'RootA::run'),
            Invariance::summarize(Invariance::source($leaf.$variable), 'RootB::run'),
        );
    }

    /**
     * Asserts a rewrite kept the result, and that the reference form was determinate.
     *
     * Requiring the reference to analyze cleanly stops a pair from passing
     * because both forms are equally unanalyzed.
     *
     * @param array<string, mixed> $reference
     * @param array<string, mixed> $rewritten
     */
    public function assertRewriteKeeps(string $expectedTtl, array $reference, array $rewritten): void
    {
        self::assertSame([], $reference['problems'], 'the reference form must analyze without problems');
        self::assertSame([], $reference['warnings'], 'the reference form must analyze without warnings');
        self::assertSame($expectedTtl, $reference['ttl'], 'the reference form must determine the lifetime');
        self::assertSame($reference, $rewritten, 'the rewrite must not change the reported metadata');
    }
}
