<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Parameter;

use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Parameter\ParameterConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterConfiguration::class)]
#[UsesNamespace('Magix\Cache')]
final class ParameterConfigurationTest extends TestCase
{
    public function testPolicyKeepsStaticRestrictionsAndAddsParameterTags(): void
    {
        $static = new CachePolicy(ttl: 60, tags: ['static'], visibility: Visibility::Private);
        $parameters = new ParameterConfiguration(ttl: 10, tags: ['dynamic'], visibility: Visibility::Shared);
        $policy = $parameters->policy($static);

        self::assertSame(60, $policy->ttl, 'the parameter TTL is applied separately at the origin base time');
        self::assertSame(Visibility::Private, $policy->visibility);
        self::assertSame(['static', 'dynamic'], $policy->tags);
    }
}
