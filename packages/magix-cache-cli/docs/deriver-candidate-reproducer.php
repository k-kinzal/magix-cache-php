<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Deriver\Analyzer;
use Deriver\Project\Configuration;
use Deriver\Project\ProjectInput;
use Deriver\Project\SourceFile;
use Deriver\Project\TargetProfile;
use Deriver\Query\Budget;
use Deriver\Query\ReturnQuery;
use Deriver\Result\Alternative;
use Deriver\Value\Term;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$cases = [
    'literal' => 'function resolve(){return 30;}',
    'dynamic-branch' => 'function resolve(bool $flag){return $flag ? 30 : 60;}',
    'same-branch' => 'function resolve(bool $flag){return $flag ? 30 : 30;}',
    'throw-branch' => 'function resolve(bool $flag){if($flag){throw new RuntimeException();}return 30;}',
    'throw-expression' => 'function resolve(bool $flag){return $flag ? throw new RuntimeException() : 30;}',
    'only-throw' => 'function resolve(){throw new RuntimeException();}',
    'unrelated-call' => 'function resolve(){unknown();return 30;}',
    'dependent-call' => 'function resolve(){return unknown();}',
    'missing-variable' => 'function resolve(){return $missing;}',
    'dynamic-array' => 'function resolve($input){return ["head", $input, "tail"] ;}',
    'dynamic-addition' => 'function resolve(int $input){return 5+$input;}',
    'input-default' => 'function resolve(int $input=30){return $input;}',
    'request-input' => 'function resolve(){return "id:".$_GET["id"] ;}',
    'environment-input' => 'function resolve(){return getenv("TTL");}',
    'known-callers' => 'function resolve(int $input){return $input+5;} function a(){return resolve(30);}function b(){return resolve(60);}',
    'property-origins' => 'class Repo {private $table="users";function archive(){$this->table="archive";}function sql(){return $this->table;}}function resolve(){return (new Repo)->sql();}',
    'branch-correlation' => 'function resolve($flag){if($flag){$ttl=30;$tag="a";}else{$ttl=60;$tag="b";}return [$ttl,$tag];}',
    'alias-correlation' => 'function resolve($flag){$ttl=$flag?30:60;$same=$ttl;return $ttl-$same;}',
    'dead-branch' => 'function resolve(){if(false){return unknown();}return 30;}',
    'finite-loop' => 'function resolve(){$sum=0;for($i=0;$i<10;$i++){$sum+=$i;}return $sum;}',
    'finite-recursion' => 'function countDown($n){if($n===0){return 0;}$n-=1;return 1+countDown($n);}function resolve(){return countDown(8);}',
    'integer-shift' => 'function resolve(){return 1<<-1;}',
    'divide-zero' => 'function resolve(){return 1/0;}',
    'static-helper' => 'function helper(){return 30;}function resolve(){return helper()+5;}',
    'catch-statement' => 'function resolve(){try{throw new RuntimeException();}catch(RuntimeException $e){return 60;}}',
    'catch-expression' => 'function resolve(){try{return throw new RuntimeException();}catch(RuntimeException $e){return 60;}}',
    'catch-arithmetic' => 'function resolve(){try{return 1/0;}catch(DivisionByZeroError $e){return 60;}}',
    'try-followed-by-return' => 'function resolve(){try{$ttl=30;}catch(RuntimeException $e){$ttl=60;}return $ttl;}',
    'finally-followed-by-return' => 'function resolve(){try{$ttl=30;}finally{}return $ttl;}',
    'incomplete-branch' => 'function resolve($flag,$input){return $flag?30:5+$input;}',
    'depth-limit' => 'function resolve(){$ttl=30;return $ttl+5;}',
    'enumeration-limit' => 'function resolve($first,$second){return ($first?1:2)+($second?10:20);}',
];
$budgets = [
    'depth-limit' => new Budget(maxDepth: 0),
    'enumeration-limit' => new Budget(partitions: 2),
];
/**
 * Records source candidates and residual dependencies; this is not an execution oracle.
 * Exception-region examples intentionally expose discrepancies for upstream review.
 */
$report = ['deriver' => InstalledVersions::getReference('k-kinzal/deriver'), 'php' => PHP_VERSION, 'contract' => 'candidates', 'cases' => []];
foreach ($cases as $name => $body) {
    $session = (new Analyzer())->open(
        new ProjectInput([new SourceFile('candidate.php', '<?php '.$body)]),
        new Configuration(new TargetProfile(floatPrecision: 14)),
    );
    $query = new ReturnQuery('resolve', budget: $budgets[$name] ?? new Budget());
    $result = $session->derive($query);
    $report['cases'][$name] = [
        'source' => '<?php '.$body,
        'definite' => $result->definite() !== null,
        'result' => json_decode($result->toJson(), true, flags: JSON_THROW_ON_ERROR),
        'native' => array_map(
            static fn (Alternative $candidate): array => array_map(
                static fn (Term $value): mixed => $value->isConcrete() ? $value->native() : $value->kind,
                $candidate->values,
            ),
            $result->normalOutcomes,
        ),
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
