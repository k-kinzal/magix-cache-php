<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use JsonSerializable;
use Override;

/**
 * Preserves a candidate daily expiration independently of its unknown duration.
 */
final readonly class ExpirationEstimate implements JsonSerializable
{
    /**
     * Creates an estimate; null fields remain dependent on runtime arguments.
     *
     * @param bool $window Whether an end time was declared, even if unresolved.
     */
    public function __construct(
        public ?string $at,
        public ?string $until = null,
        public ?string $timezone = 'UTC',
        public bool $window = false,
    ) {
    }

    /**
     * Reports whether a resolved window ends on the following local date.
     */
    public function crossesMidnight(): ?bool
    {
        if (!$this->window) {
            return false;
        }

        if ($this->at === null || $this->until === null) {
            return null;
        }

        return str_pad($this->until, 8, ':00') < str_pad($this->at, 8, ':00');
    }

    /**
     * Renders a local time/window with its timezone and explicit date rollover.
     */
    public function label(): string
    {
        $time = $this->at ?? '?';

        if ($this->window) {
            $time .= '-'.($this->until ?? '?').($this->crossesMidnight() === true ? ' (+1 day)' : '');
        }

        return 'daily '.$time.' '.($this->timezone ?? '?');
    }

    /**
     * Describes simultaneous expiration constraints without ordering clock faces.
     *
     * @param list<self> $expirations
     */
    public static function describe(array $expirations): string
    {
        $labels = array_values(array_unique(array_map(static fn (self $expiration): string => $expiration->label(), $expirations)));

        return count($labels) <= 1 ? implode('', $labels) : 'earliest of ('.implode('; ', $labels).')';
    }

    /**
     * Keeps clock fields separate from TTL seconds for machine consumers.
     *
     * @return array{at: string|null, until: string|null, timezone: string|null, window: bool, crossesMidnight: bool|null, recurrence: string}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['at' => $this->at, 'until' => $this->until, 'timezone' => $this->timezone,
            'window' => $this->window, 'crossesMidnight' => $this->crossesMidnight(), 'recurrence' => 'daily'];
    }
}
