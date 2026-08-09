<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Integrations\RateLimit\RateLimiterLimit;
use Docuccino\Laravel\Integrations\RateLimit\RateLimiterLimitVisitor;
use Docuccino\Laravel\Tests\Support\StubTraceScope;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * In-process proof of the named-limiter fold half over REAL php-parser nodes (a stub TypeScope stands
 * in for the engine, folding factory static-calls to descriptors exactly as it does). Each `Limit::per*`
 * factory maps to its window in seconds, and every bail shape (multiple/conditional returns, a
 * non-literal argument, `Limit::none()`, an array of limits, a `->response(…)` custom body) degrades to
 * the numberless floor. Complements the real-engine fixture test that proves the closure trace reaches
 * an idiomatic arrow limiter. Drives the visitor the way the engine's closure trace does — one return
 * expression per `enterNode`, not a full-tree traversal.
 */
function foldLimiterReturns(string ...$returns): RateLimiterLimit
{
    $visitor = new RateLimiterLimitVisitor;
    $scope = new StubTraceScope(new ClassT('Illuminate\\Cache\\RateLimiting\\Limit'));
    $parser = (new ParserFactory)->createForNewestSupportedVersion();

    foreach ($returns as $return) {
        $ast = $parser->parse("<?php\nreturn ".$return.";\n") ?? [];
        $statement = $ast[0] ?? null;
        if ($statement instanceof Node\Stmt\Return_ && $statement->expr !== null) {
            $visitor->enterNode($statement->expr, $scope);
        }
    }

    return $visitor->limit;
}

it('folds each Limit::per* factory to its window in seconds', function (string $call, int $max, int $decaySeconds): void {
    $limit = foldLimiterReturns('\\Illuminate\\Cache\\RateLimiting\\Limit::'.$call);

    expect($limit->resolved())->toBeTrue()
        ->and($limit->bailed)->toBeFalse()
        ->and($limit->maxAttempts)->toBe($max)
        ->and($limit->decaySeconds)->toBe($decaySeconds);
})->with([
    'perSecond' => ['perSecond(5)', 5, 1],
    'perMinute' => ['perMinute(60)', 60, 60],
    'perMinutes' => ['perMinutes(3, 90)', 90, 180],
    'perHour' => ['perHour(100)', 100, 3600],
    'perDay' => ['perDay(1000)', 1000, 86400],
    'trailing ->by() partition key is ignored' => ["perMinute(60)->by('user-1')", 60, 60],
]);

it('bails to the numberless floor for an unfoldable limiter', function (string $return): void {
    $limit = foldLimiterReturns($return);

    expect($limit->resolved())->toBeFalse()
        ->and($limit->bailed)->toBeTrue()
        ->and($limit->maxAttempts)->toBeNull()
        ->and($limit->decaySeconds)->toBeNull();
})->with([
    'unlimited (Limit::none)' => ['\\Illuminate\\Cache\\RateLimiting\\Limit::none()'],
    'non-literal argument' => ['\\Illuminate\\Cache\\RateLimiting\\Limit::perMinute($max)'],
    'array of limits' => ['[\\Illuminate\\Cache\\RateLimiting\\Limit::perMinute(60)]'],
    '->response() custom body' => ["\\Illuminate\\Cache\\RateLimiting\\Limit::perMinute(60)->response(function () { return 'x'; })"],
    // A match-expression body: constantValueOf on a Match_ returns no descriptor → numberless floor.
    'match-expression body' => ['match (true) { default => \\Illuminate\\Cache\\RateLimiting\\Limit::perMinute(10) }'],
]);

it('bails on multiple returns (a conditional limiter)', function (): void {
    $limit = foldLimiterReturns(
        '\\Illuminate\\Cache\\RateLimiting\\Limit::perMinute(10)',
        '\\Illuminate\\Cache\\RateLimiting\\Limit::perMinute(60)',
    );

    expect($limit->resolved())->toBeFalse()
        ->and($limit->bailed)->toBeTrue()
        ->and($limit->returnsSeen)->toBe(2);
});
