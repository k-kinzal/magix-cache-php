<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\ExpirationContract;
use Magix\Cache\Cli\Declaration\ParameterReference;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Strategy\Contract\ExpiresAt;

/**
 * Binds daily times while retaining finite-expiration proof and runtime uncertainty.
 */
final readonly class ExpirationBinding
{
    /**
     * Resolves one contract, reporting errors without running attribute validation.
     *
     * @param array<string, mixed> $constructor
     * @return array{ExpirationEstimate, list<string>}
     */
    public function resolve(ExpirationContract $contract, array $constructor, string $subject): array
    {
        $values = [];
        $problems = $contract->problems;

        foreach (['at' => $contract->at, 'until' => $contract->until, 'timezone' => $contract->timezone] as $name => $declared) {
            [$value, $problem] = $this->value($declared, $constructor);
            $problem ??= $this->problem($name, $value);

            if ($problem !== null) {
                $problems[] = $problem;
            }

            $values[$name] = $value;
        }

        return [new ExpirationEstimate(
            at: is_string($values['at']) ? $values['at'] : null,
            until: is_string($values['until']) ? $values['until'] : null,
            timezone: is_string($values['timezone']) ? $values['timezone'] : null,
            window: $values['until'] !== null,
        ), array_map(static fn (string $problem): string => $subject.': '.$problem, $problems)];
    }

    /**
     * Follows a constructor reference without substituting defaults for unknown values.
     *
     * @param array<string, mixed> $constructor
     * @return array{mixed, string|null}
     */
    public function value(mixed $declared, array $constructor): array
    {
        if (!$declared instanceof ContractReference) {
            return [$declared, null];
        }

        if ($declared->source !== ContractSource::Constructor) {
            return [Unresolved::Value, $declared->label().' cannot bind here; use ConstructorArg'];
        }

        if (!array_key_exists($declared->name, $constructor)) {
            return [Unresolved::Value, 'references '.$declared->label().', but no such parameter is declared'];
        }

        return [$constructor[$declared->name], null];
    }

    /**
     * Checks resolved values using the same grammar as the public contract.
     */
    public function problem(string $name, mixed $value): ?string
    {
        if ($value instanceof Unresolved || $value instanceof ParameterReference || ($name === 'until' && $value === null)) {
            return null;
        }

        if (!is_string($value)) {
            return $name.' must be a string'.($name === 'until' ? ' or null' : '');
        }

        if ($name === 'timezone') {
            return ExpiresAt::validTimezone($value) ? null : 'timezone must be an IANA timezone identifier';
        }

        return ExpiresAt::validTime($value) ? null : $name.' must be a valid HH:MM or HH:MM:SS time';
    }
}
