<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Metadata\Visibility;

/**
 * Interprets parameter constraints in the analyzer's abstract value domain.
 */
final readonly class ParameterEffects
{
    /**
     * @return list<string> Parameter names contributing this constraint.
     */
    public function sources(BoundaryDeclaration $boundary, string $kind): array
    {
        $sources = [];

        foreach ($boundary->parameters as $parameter) {
            $configuration = $parameter->configuration;
            $present = match ($kind) {
                'ttl' => $configuration?->ttl === true,
                'tags' => $configuration?->tags === true,
                'visibility' => $configuration?->visibility === true,
                default => false,
            };

            if ($present) {
                $sources[] = '$'.$parameter->name;
            }
        }

        return $sources;
    }

    /**
     * Returns a finite but runtime-dependent lifetime constraint when bound.
     */
    public function ttl(BoundaryDeclaration $boundary): ?TtlEstimate
    {
        $sources = $this->sources($boundary, 'ttl');

        return $sources === [] ? null : TtlEstimate::unknown(
            condition: 'TTL depends on '.implode(', ', $sources).' (non-negative integer seconds)',
            lowerBound: 0,
            finite: true,
        );
    }

    /**
     * @return list<string> Declarations that cannot work at runtime.
     */
    public function problems(BoundaryDeclaration $boundary): array
    {
        $problems = [];
        $destinations = [];

        foreach ($boundary->parameters as $parameter) {
            $configuration = $parameter->configuration;

            if ($configuration === null) {
                continue;
            }

            $problems = [...$problems, ...$this->parameterProblems($parameter)];
            $target = $configuration->strategyArgument;

            if ($target === null) {
                continue;
            }

            if ($boundary->useStrategy === null) {
                $problems[] = '$'.$parameter->name.' requires an enabled #[UseStrategy]';
            }

            if (isset($destinations[$target])) {
                $problems[] = 'multiple parameters supply strategy argument $'.$target;
            }

            $destinations[$target] = true;
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    public function parameterProblems(KeyParameter $parameter): array
    {
        $configuration = $parameter->configuration;

        if ($configuration === null) {
            return [];
        }

        $problems = $configuration->problems;

        if ($parameter->ignored || $parameter->variadic) {
            $problems[] = 'a cache configuration parameter cannot be ignored or variadic';
        }

        $expected = [];

        if ($configuration->ttl) {
            $expected[] = 'int';
        }

        if ($configuration->tags) {
            $expected[] = 'array';
        }

        if ($configuration->visibility) {
            $expected[] = Visibility::class;
        }

        if (count($expected) > 1) {
            $problems[] = 'one value cannot supply incompatible cache constraint types';
        }

        foreach ($expected as $type) {
            if (!$this->accepts($parameter->type, $type)) {
                $problems[] = 'requires '.$type.', incompatible with declared '.($parameter->type ?? 'mixed');
            }
        }

        return array_map(static fn (string $problem): string => '$'.$parameter->name.': '.$problem, $problems);
    }

    /**
     * Checks only disjoint native types; actual values are validated at runtime.
     */
    public function accepts(?string $declared, string $expected): bool
    {
        $types = explode('|', str_replace('?', '', $declared ?? 'mixed'));

        return in_array('mixed', $types, true) || in_array($expected, $types, true)
            || ($expected === 'array' && in_array('iterable', $types, true))
            || ($expected === Visibility::class && array_intersect(['object', 'UnitEnum', 'BackedEnum'], $types) !== []);
    }

    /**
     * Preserves proven metadata bounds while exposing invocation dependence.
     */
    public function apply(BoundaryDeclaration $boundary, DependencyConstraint $constraint, CacheEffect $effect): CacheEffect
    {
        $visibilitySources = $this->sources($boundary, 'visibility');
        $visibilityUnknown = $effect->visibility !== Visibility::NoStore
            && ($constraint->visibilityUnknown || $visibilitySources !== []);
        $tagsUnknown = $constraint->tagsUnknown || $this->sources($boundary, 'tags') !== [];
        $problems = [...$effect->problems, ...$this->problems($boundary)];
        $reason = $effect->visibilityReason;

        if ($visibilityUnknown) {
            $dependency = $visibilitySources === [] ? 'dependency metadata' : implode(', ', $visibilitySources);
            $reason = ($reason === null ? '' : $reason.'; ').'may be further restricted by '.$dependency;
        }

        return new CacheEffect(
            ttl: $problems === [] ? $effect->ttl : TtlEstimate::invalid($problems[0]),
            visibility: $effect->visibility,
            storable: $effect->storable && !$visibilityUnknown && $problems === [],
            tags: $effect->tags,
            visibilityReason: $reason,
            problems: $problems,
            strategy: $effect->strategy,
            visibilityUnknown: $visibilityUnknown,
            tagsUnknown: $tagsUnknown,
        );
    }
}
