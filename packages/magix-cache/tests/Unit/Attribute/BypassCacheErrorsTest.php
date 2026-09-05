<?php

declare(strict_types=1);

namespace Tests\Unit\Attribute;

use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Runtime\Extension\DefaultBackendErrorClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BypassCacheErrors::class)]
final class BypassCacheErrorsTest extends TestCase
{
    public function testDeclarationDefaultsToTheRuntimeClassifier(): void
    {
        $behavior = new BypassCacheErrors();

        self::assertNull($behavior->classifier);
        self::assertTrue($behavior->enabled);
    }

    public function testDeclarationCarriesAClassifierReference(): void
    {
        self::assertSame(
            DefaultBackendErrorClassifier::class,
            (new BypassCacheErrors(classifier: DefaultBackendErrorClassifier::class))->classifier,
        );
    }

    public function testDisablingDeclarationIsPreserved(): void
    {
        self::assertFalse((new BypassCacheErrors(enabled: false))->enabled);
    }
}
