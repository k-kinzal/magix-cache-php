<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\ParameterConfiguration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Cli\Graph\ParameterEffects;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterEffects::class)]
#[UsesNamespace('Magix\Cache')]
final class ParameterEffectsTest extends TestCase
{
    public function testSourcesNamesTheParametersContributingOneConstraint(): void
    {
        $boundary = new BoundaryDeclaration('Query', 'fetch', 'a.php', 1, parameters: [
            new KeyParameter('ttl', configuration: new ParameterConfiguration(ttl: true)),
            new KeyParameter('tags', configuration: new ParameterConfiguration(tags: true)),
        ]);

        self::assertSame(['$ttl'], (new ParameterEffects())->sources($boundary, 'ttl'));
    }

    public function testTtlStaysUnknownEvenWhenTheParameterIsOptional(): void
    {
        $boundary = new BoundaryDeclaration('Query', 'fetch', 'a.php', 1, parameters: [
            new KeyParameter('ttl', 'int', optional: true, configuration: new ParameterConfiguration(ttl: true)),
        ]);
        $ttl = (new ParameterEffects())->ttl($boundary);

        self::assertNotNull($ttl);
        self::assertSame(TtlEstimateState::Unknown, $ttl->state);
        self::assertNull($ttl->seconds);
        self::assertSame(0, $ttl->lowerBound);
        self::assertStringContainsString('$ttl', $ttl->reason ?? '');
    }

    public function testProblemsReportsMissingStrategiesAndDuplicateDestinations(): void
    {
        $boundary = new BoundaryDeclaration('Query', 'fetch', 'a.php', 1, parameters: [
            new KeyParameter('first', configuration: new ParameterConfiguration(strategyArgument: 'min')),
            new KeyParameter('second', configuration: new ParameterConfiguration(strategyArgument: 'min')),
        ]);
        $problems = (new ParameterEffects())->problems($boundary);

        self::assertCount(3, $problems);
        self::assertStringContainsString('enabled #[UseStrategy]', $problems[0]);
        self::assertStringContainsString('multiple parameters', $problems[2]);
    }

    public function testParameterProblemsReportsInvalidKeyParticipationAndTypes(): void
    {
        $parameter = new KeyParameter('ttl', 'string', ignored: true, configuration: new ParameterConfiguration(ttl: true));
        $problems = (new ParameterEffects())->parameterProblems($parameter);

        self::assertCount(2, $problems);
        self::assertStringContainsString('ignored', $problems[0]);
        self::assertStringContainsString('requires int', $problems[1]);
    }

    public function testAcceptsKeepsUncertainTypesForRuntimeValidation(): void
    {
        $effects = new ParameterEffects();

        self::assertTrue($effects->accepts('?int', 'int'));
        self::assertTrue($effects->accepts('mixed', 'int'));
        self::assertTrue($effects->accepts('iterable', 'array'));
        self::assertFalse($effects->accepts('string', 'int'));
    }

    public function testApplyKeepsProvenVisibilityAndTagsWhileMarkingRuntimeAdditions(): void
    {
        $boundary = new BoundaryDeclaration('Query', 'fetch', 'a.php', 1, parameters: [
            new KeyParameter('visibility', Visibility::class, configuration: new ParameterConfiguration(visibility: true)),
            new KeyParameter('tags', 'array', configuration: new ParameterConfiguration(tags: true)),
        ]);
        $effect = (new ParameterEffects())->apply($boundary, new DependencyConstraint(), new CacheEffect(
            ttl: TtlEstimate::known(60),
            visibility: Visibility::Private,
            storable: true,
            tags: ['fixed'],
        ));

        self::assertSame(Visibility::Private, $effect->visibility);
        self::assertTrue($effect->visibilityUnknown);
        self::assertSame(['fixed'], $effect->tags);
        self::assertTrue($effect->tagsUnknown);
        self::assertFalse($effect->storable);
    }
}
