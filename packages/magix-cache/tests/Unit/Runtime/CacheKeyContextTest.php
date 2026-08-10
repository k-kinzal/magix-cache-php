<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Runtime\CacheKeyContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheKeyContext::class)]
final class CacheKeyContextTest extends TestCase
{
    public function testPropertiesExposeNormalizedInvocation(): void
    {
        $context = new CacheKeyContext(
            class: 'App\\ProductQuery',
            method: 'execute',
            arguments: ['productId' => 1],
            version: '2',
        );

        self::assertSame('App\\ProductQuery', $context->class);
        self::assertSame('execute', $context->method);
        self::assertSame(['productId' => 1], $context->arguments);
        self::assertSame('2', $context->version);
    }
}
