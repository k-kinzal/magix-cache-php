<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Magix\Cache\Cli\Console\Application;
use Magix\Cache\Cli\Reader\LiteralReader;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\Int_;
use Symfony\Component\Console\Tester\CommandTester;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$arguments = $argv ?? [];
$baseline = $arguments[1] ?? null;
$original = $baseline !== null && $baseline !== '--arrays';

/**
 * Optionally preload the two original readers before application autoloading.
 * Both modes use the same dependencies, fixtures and rendering code.
 */
if ($original) {
    require $baseline.'/LiteralReader.php';
    require $baseline.'/StrategyReader.php';
}

$arrays = in_array('--arrays', $arguments, true);
$cases = $arrays ? [] : [
    'project-controller' => ['Project', 'ProductController::show'],
    'functional-composition' => ['FunctionalComposition', 'Page'],
    'expiration' => ['Expiration', 'NoonPage'],
    'async-composition' => ['AsyncComposition', 'AsyncQueries'],
];
$report = [
    'php' => PHP_VERSION,
    'deriver' => InstalledVersions::getReference('k-kinzal/deriver'),
    'reader' => $original ? 'original' : 'integrated',
    'warmups' => $arrays ? 1 : 3,
    'samples' => $arrays ? 1 : 20,
    'cases' => [],
];

foreach ($cases as $name => [$path, $boundary]) {
    $elapsed = [];
    $hashes = [];

    for ($index = 0; $index < 23; ++$index) {
        $start = hrtime(true);
        $tester = new CommandTester((new Application($root))->console()->find('analyze'));
        $status = $tester->execute([
            'boundary' => $boundary,
            '--path' => ['packages/magix-cache-cli/tests/Fixture/'.$path],
            '--format' => 'json',
            '--uncached' => 'all',
        ], ['decorated' => false]);
        $duration = (hrtime(true) - $start) / 1_000_000;

        if ($status !== 0) {
            fwrite(STDERR, $tester->getDisplay());

            exit(1);
        }

        if ($index >= 3) {
            $elapsed[] = $duration;
            $hashes[] = hash('sha256', $tester->getDisplay());
        }
    }

    $report['cases'][$name] = [
        'milliseconds' => $elapsed,
        'outputHashes' => array_values(array_unique($hashes)),
    ];
}

if ($arrays) {
    $reader = new LiteralReader();
    $reader->value(new Array_([]));

    foreach ([128, 1024, 4096, 8192] as $size) {
        $expected = range(0, $size - 1);
        $expression = new Array_(array_map(static fn (int $value): ArrayItem => new ArrayItem(new Int_($value)), $expected));
        $start = hrtime(true);
        $value = $reader->value($expression);
        $report['cases']['array-'.$size] = [
            'milliseconds' => (hrtime(true) - $start) / 1_000_000,
            'matchesOriginalValue' => $value === $expected,
            'resolved' => is_array($value),
            'count' => is_array($value) ? count($value) : null,
        ];
    }
}

try {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (JsonException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);

    exit(1);
}
