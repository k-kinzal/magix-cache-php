<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\PolicySource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PolicySource::class)]
final class PolicySourceTest extends TestCase
{
    public function testSourcesAreDistinct(): void
    {
        self::assertNotSame(PolicySource::MethodAttribute, PolicySource::ClassAttribute);
        self::assertNotSame(PolicySource::ExplicitPolicy, PolicySource::Unresolved);
    }
}
