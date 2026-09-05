<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

/**
 * Describes one normalized method invocation for a cache-key strategy.
 *
 * Two boundaries share an entry only when every field agrees: the runtime
 * namespace, the concrete class, the declaring method, the normalized
 * arguments, the application version, and the fingerprint of the effective
 * declaration.
 */
final readonly class CacheKeyContext
{
    /**
     * Creates a cache-key context.
     *
     * @param string $class The concrete class the boundary was invoked on.
     * @param string $declaringClass The class that declares the boundary method.
     * @param array<string, mixed> $arguments
     * @param string $fingerprint Canonical digest of the effective declaration.
     */
    public function __construct(
        public string $namespace,
        public string $class,
        public string $declaringClass,
        public string $method,
        public array $arguments,
        public string $version,
        public string $fingerprint,
    ) {
    }

    /**
     * Returns this context placed in a runtime's key namespace.
     */
    public function withNamespace(string $namespace): self
    {
        return new self(
            namespace: $namespace,
            class: $this->class,
            declaringClass: $this->declaringClass,
            method: $this->method,
            arguments: $this->arguments,
            version: $this->version,
            fingerprint: $this->fingerprint,
        );
    }
}
