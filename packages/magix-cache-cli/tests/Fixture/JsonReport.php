<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture;

use JsonException;
use PHPUnit\Framework\Assert;

/**
 * Reads and validates a selected root from the public JSON report envelope.
 */
final class JsonReport
{
    /**
     * @return array<array-key, mixed>
     * @throws JsonException
     */
    public static function root(string $json): array
    {
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($report);
        Assert::assertArrayHasKey('roots', $report);
        Assert::assertIsArray($report['roots']);
        Assert::assertArrayHasKey(0, $report['roots']);
        $root = $report['roots'][0];
        Assert::assertIsArray($root);

        return $root;
    }
}
