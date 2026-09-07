<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Runtime\CacheInvocation;
use Magix\Cache\Runtime\CacheKeyContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CacheInvocation::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CachePolicy::class)]
#[UsesClass(CacheKeyContext::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Attribute\StaleIfError::class)]
#[UsesClass(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class)]
#[UsesClass(\Magix\Cache\Strategy\StrategyArguments::class)]
#[UsesClass(\Magix\Cache\Strategy\StrategyDefinition::class)]
final class CacheInvocationTest extends TestCase
{
    public function testInvocationBundlesTheExecutionInputs(): void
    {
        $context = new CacheKeyContext('', 'App\\Query', 'App\\Query', 'execute', ['id' => 1], '1', 'digest');
        $policy = new CachePolicy(ttl: 20);
        $origin = static fn (): Cached => Cached::of('value');

        $invocation = new CacheInvocation(context: $context, policy: $policy, origin: $origin);

        self::assertSame($context, $invocation->context);
        self::assertSame($policy, $invocation->policy);
        self::assertSame('value', ($invocation->origin)()->value());
        self::assertNull($invocation->strategy);
        self::assertNull($invocation->dynamicTtl);
        self::assertNull($invocation->bypassCacheErrors);
    }
    public function testInvocationNormalizesTheAttributeIntoAStaleStrategyDefinition(): void
    {
        $invocation = new CacheInvocation(
            new CacheKeyContext('', 'Q', 'Q', 'execute', [], '1', 'f'),
            new CachePolicy(ttl: 20),
            static fn (): Cached => Cached::of('value'),
            staleIfError: new \Magix\Cache\Attribute\StaleIfError(maxAge: 30, exceptions: [RuntimeException::class]),
        );
        self::assertNotNull($invocation->strategy);
        self::assertInstanceOf(\Magix\Cache\Strategy\StaleIfErrorCacheStrategy::class, $invocation->strategy->instantiate());
    }
}
