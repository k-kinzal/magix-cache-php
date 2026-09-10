<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\MetadataContract;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use Magix\Cache\Cli\Declaration\StrategyInstantiation;
use Magix\Cache\Cli\Declaration\TtlAssumption;
use Magix\Cache\Cli\Declaration\TtlContract;

use function strrpos;
use function substr;

/**
 * Derives the composed contract of a declared strategy without running it.
 *
 * The resolver binds the #[UseStrategy] arguments to the same create() the
 * runtime calls, follows the composed constructions in order, binds each
 * child contract's explicit references to the constructor values it was
 * built with, and selects overrides in fetch return order (outermost wins).
 * A composition never re-declares child contracts; only an explicit
 * assumption replaces the one analysis item it names. What stays unknown
 * stays unknown — it is never converted into a default or into "no
 * constraint".
 */
final readonly class StrategyResolver
{
    /**
     * Creates a resolver over one catalog.
     */
    public function __construct(
        private Catalog $catalog,
        private ReflectedStrategies $reflected = new ReflectedStrategies(),
        private ContractBinding $binding = new ContractBinding(),
    ) {
    }

    /**
     * Returns the analyzed strategy effect of a boundary, when one is declared.
     */
    public function resolve(BoundaryDeclaration $boundary): ?StrategyEffect
    {
        $use = $boundary->useStrategy;

        if ($use === null) {
            return null;
        }

        $declaration = $this->declaration($use->strategy);

        if ($declaration === null) {
            return new StrategyEffect(
                label: $use->label(),
                ttl: TtlEstimate::unknown(condition: $use->strategy.' was not found in the scanned sources'),
                writes: MetadataContract::undescribed(),
            );
        }

        if (!$declaration->hasCreate) {
            $problem = $use->strategy.' declares no public static create(), so resolving the strategy throws a LogicException';

            return new StrategyEffect($use->label(), TtlEstimate::invalid($problem), [], null, [$problem]);
        }

        if ($declaration->definitionProblem !== null) {
            $problem = $declaration->definitionProblem;

            return new StrategyEffect($use->label(), TtlEstimate::invalid($problem), [], null, [$problem]);
        }

        [$use, $bindingProblems] = (new ParameterStrategyBinding())->bind($boundary, $use, $declaration);
        [$environment, $problems] = $this->binding->bindCreate($declaration, $use->arguments);
        $problems = [...$problems, ...$bindingProblems];
        $composed = $this->composition($declaration, $environment);
        $ttl = $problems === [] ? $composed->ttl : TtlEstimate::invalid($problems[0]);

        return new StrategyEffect($use->label(), $ttl, $composed->steps, $composed->overridesExpiration, [...$problems, ...$composed->problems], $composed->expirations, $composed->writes);
    }

    /**
     * Returns the declaration of a strategy class from any readable source.
     */
    public function declaration(string $class): ?StrategyDeclaration
    {
        return $this->catalog->strategy($class) ?? $this->reflected->read($class);
    }

    /**
     * Returns the composed candidate constraint of one bound composition.
     *
     * @param array<string, mixed> $environment
     */
    public function composition(StrategyDeclaration $declaration, array $environment): StrategyEffect
    {
        if ($declaration->definitionProblem !== null) {
            $problem = $declaration->definitionProblem;

            return new StrategyEffect('', TtlEstimate::invalid($problem), problems: [$problem]);
        }

        if ($declaration->composed === null) {
            $condition = $declaration->notes[0] ?? ('the composition of '.$declaration->shortName().' cannot be read statically');

            return new StrategyEffect('', TtlEstimate::unknown(condition: $condition), writes: MetadataContract::undescribed());
        }

        $ttl = TtlEstimate::unconstrained();
        $steps = [];
        $expirations = [];
        $problems = [];
        $adds = false;
        $open = false;
        $writes = new MetadataContract();

        foreach (array_reverse($declaration->composed) as $instantiation) {
            [$step, $stepProblems, $stepAdds] = $this->step($declaration, $instantiation, $environment);
            $steps[] = $step;
            $writes = $writes->merge($step->writes);
            $problems = [...$problems, ...$stepProblems];
            if ($stepAdds !== false) {
                $ttl = $step->ttl;
                $expirations = $step->expirations;
                $adds = $stepAdds === true;
                $open = $stepAdds === null;
            }
        }

        return new StrategyEffect('', $problems === [] ? $ttl : TtlEstimate::invalid($problems[0]), array_reverse($steps), $adds ? true : ($open ? null : false), $problems, $expirations, $writes);
    }

    /**
     * Returns the analyzed contribution of one composed construction.
     *
     * @param array<string, mixed> $environment
     * @return array{StrategyStep, list<string>, bool|null}
     */
    public function step(StrategyDeclaration $declaration, StrategyInstantiation $instantiation, array $environment): array
    {
        if ($instantiation->problem !== null) {
            $problem = $instantiation->problem;

            return [new StrategyStep($instantiation->class, TtlEstimate::invalid($problem)), [$problem], null];
        }

        $child = $this->declaration($instantiation->class);
        $assumption = $declaration->assumptionFor($instantiation->class);

        if ($instantiation->viaCreate) {
            if ($child !== null && $child->hasCreate && $child->composed !== null) {
                return $this->created($child, $instantiation, $environment);
            }

            if ($assumption !== null) {
                return $this->assumed($instantiation->class, $assumption, $environment);
            }

            $condition = $this->shortName($instantiation->class).'::create() cannot be analyzed statically';

            return [new StrategyStep($instantiation->class, TtlEstimate::unknown(condition: $condition), writes: MetadataContract::undescribed()), [], null];
        }

        if ($child !== null && !$child->constructible) {
            $problem = $child->name.' is not a constructible CacheStrategy for StrategyDefinition::of()';

            return [new StrategyStep($child->name, TtlEstimate::invalid($problem)), [$problem], null];
        }

        if ($child === null || ($child->ttl === null && $child->expirations === [])) {
            if ($assumption !== null) {
                return $this->assumed($instantiation->class, $assumption, $environment);
            }

            if ($child === null) {
                $condition = $instantiation->class.' was not found in the scanned sources';

                return [new StrategyStep($instantiation->class, TtlEstimate::unknown(condition: $condition), writes: MetadataContract::undescribed()), [], null];
            }
        }

        return $this->contracted($child, $instantiation, $environment);
    }

    /**
     * Follows a nested composition built by a child create().
     *
     * @param array<string, mixed> $environment
     * @return array{StrategyStep, list<string>, bool|null}
     */
    public function created(StrategyDeclaration $child, StrategyInstantiation $instantiation, array $environment): array
    {
        $arguments = $this->binding->values($instantiation->arguments, $environment);
        [$childEnvironment, $problems] = $this->binding->bindCreate($child, $arguments);
        $composed = $this->composition($child, $childEnvironment);
        $ttl = $problems === [] ? $composed->ttl : TtlEstimate::invalid($problems[0]);

        return [new StrategyStep($instantiation->class, $ttl, expirations: $composed->expirations, writes: $composed->writes), [...$problems, ...$composed->problems], $composed->overridesExpiration];
    }

    /**
     * Binds the declared contract of one constructed strategy.
     *
     * @param array<string, mixed> $environment
     * @return array{StrategyStep, list<string>, bool|null}
     */
    public function contracted(StrategyDeclaration $child, StrategyInstantiation $instantiation, array $environment): array
    {
        $contract = $child->ttl ?? new TtlContract(unconstrained: true);

        [$constructor, $problems] = $this->binding->bindConstructor($child, $instantiation->arguments, $environment);
        $subject = '#[Ttl] on '.$child->shortName().'::fetch()';
        [$estimate, $contractProblems] = $this->binding->contract($contract, ContractSource::Constructor, $constructor, $subject);
        $problems = [...$problems, ...$contractProblems];
        $expirations = [];

        foreach ($child->expirations as $expirationContract) {
            [$expiration, $expirationProblems] = (new ExpirationBinding())->resolve($expirationContract, $constructor, '#[ExpiresAt] on '.$child->shortName().'::fetch()');
            $problems = [...$problems, ...$expirationProblems];
            $expirations[] = $expiration;
            $estimate = $estimate->meet(TtlEstimate::unknown(condition: 'duration depends on the origin time and daily expiration', finite: true));
        }

        if ($problems !== []) {
            return [new StrategyStep($child->name, TtlEstimate::invalid($problems[0])), $problems, true];
        }

        return [new StrategyStep($child->name, $estimate, expirations: $expirations, writes: $child->writes), [], !$contract->unconstrained || $expirations !== []];
    }

    /**
     * Applies the explicit assumption declared for one composed strategy.
     *
     * @param array<string, mixed> $environment
     * @return array{StrategyStep, list<string>, bool|null}
     */
    public function assumed(string $class, TtlAssumption $assumption, array $environment): array
    {
        $subject = '#[AssumeTtl] for '.$this->shortName($class);
        [$estimate, $problems] = $this->binding->contract($assumption->contract(), ContractSource::Create, $environment, $subject);

        if ($problems !== []) {
            return [new StrategyStep($class, TtlEstimate::invalid($problems[0]), assumed: true), $problems, true];
        }

        return [new StrategyStep($class, $estimate, assumed: true, writes: MetadataContract::undescribed()), [], !$assumption->unconstrained];
    }

    /**
     * Returns a class name without its namespace.
     */
    public function shortName(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }
}
