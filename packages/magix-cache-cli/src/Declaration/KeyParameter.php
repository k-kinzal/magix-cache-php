<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

use function implode;

use Magix\Cache\Runtime\Metadata\Visibility;

use function strtolower;

/**
 * Holds one method parameter together with its cache key attributes.
 */
final readonly class KeyParameter
{
    /**
     * Creates a parameter declaration.
     *
     * @param bool $optional Whether callers may omit the argument.
     */
    public function __construct(
        public string $name,
        public ?string $type = null,
        public bool $ignored = false,
        public ?Visibility $scope = null,
        public ?string $reducer = null,
        public bool $variadic = false,
        public bool $optional = false,
    ) {
    }

    /**
     * Returns the parameter rendered with the attributes that shape the key.
     */
    public function label(): string
    {
        $notes = [];

        if ($this->ignored) {
            $notes[] = 'ignored';
        }

        if ($this->scope !== null) {
            $notes[] = 'scope '.strtolower($this->scope->name);
        }

        if ($this->reducer !== null) {
            $notes[] = 'reduced by '.$this->reducer;
        }

        $name = ($this->variadic ? '...$' : '$').$this->name;

        return $notes === [] ? $name : $name.' ('.implode(', ', $notes).')';
    }
}
