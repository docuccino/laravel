<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;

/**
 * Folds a named limiter's `RateLimiter::for($name, …)` closure to a concrete limit. The engine's closure
 * trace feeds this one return expression per closure return (not every node), so it navigates each
 * return's subtree itself and never requests descent.
 *
 * It recovers a single unconditional `return Limit::perSecond/perMinute/perMinutes/perHour/perDay(…)` of
 * literal ints — i.e. the Laravel skeleton default, `fn ($r) => Limit::perMinute(60)->by(…)`. A trailing
 * `->by(…)` partition key is ignored, having no doc effect. Everything else bails and leaves
 * {@see RateLimiterLimit::$bailed} set so the extension keeps its numberless-429 + diagnostic floor:
 * multiple or conditional returns, a non-literal argument, `Limit::none()`, an array of limits, or a
 * `->response(…)` custom body.
 */
final class RateLimiterLimitVisitor implements TraceVisitor
{
    private const LIMIT = 'Illuminate\\Cache\\RateLimiting\\Limit';

    /**
     * Fixed-window factory → window length in seconds. `perMinutes` is handled separately; its decay is
     * an argument, not fixed.
     *
     * @var array<string, int>
     */
    private const WINDOWS = [
        'perSecond' => 1,
        'perMinute' => 60,
        'perHour' => 3600,
        'perDay' => 86400,
    ];

    public function __construct(
        public readonly RateLimiterLimit $limit = new RateLimiterLimit,
    ) {}

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if (! $node instanceof Node\Expr) {
            return false;
        }

        $this->limit->returnsSeen++;
        if ($this->limit->returnsSeen > 1) {
            // A second return means the limiter branches, so the limit is conditional.
            $this->bail();

            return false;
        }

        $base = $this->peelChain($node);
        if ($base === null) {
            return false;
        }

        $this->fold($base, $scope);

        return false;
    }

    /** Strips trailing `->by(…)` calls to get at the base `Limit::…`. Any other trailing method bails. */
    private function peelChain(Node\Expr $expr): ?Node\Expr
    {
        while ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            if ($expr->name->toString() !== 'by') {
                $this->bail(); // ->response(…) custom body, or an unrecognised chain

                return null;
            }

            $expr = $expr->var;
        }

        return $expr;
    }

    /** Fold a `Limit::per*(…)` static call into {@see RateLimiterLimit}, or bail. */
    private function fold(Node\Expr $base, TypeScope $scope): void
    {
        $value = $scope->constantValueOf($base);
        if ($value === null || ! $value->isDescriptor()) {
            // An array of limits, a ternary, a variable — nothing foldable to a single Limit call.
            $this->bail();

            return;
        }

        $factory = (string) $value->factory;
        $separator = strrpos($factory, '::');
        $class = $separator === false ? $factory : substr($factory, 0, $separator);
        $method = $separator === false ? '' : substr($factory, $separator + 2);

        if ($class !== self::LIMIT) {
            $this->bail();

            return;
        }

        if ($method === 'perMinutes') {
            $decayMinutes = $this->intArg($value, 0);
            $max = $this->intArg($value, 1);
            if ($decayMinutes === null || $max === null) {
                $this->bail();

                return;
            }

            $this->limit->maxAttempts = $max;
            $this->limit->decaySeconds = $decayMinutes * 60;

            return;
        }

        if (! isset(self::WINDOWS[$method])) {
            // `none()`/unlimited, or a factory outside the fixed-window set.
            $this->bail();

            return;
        }

        $max = $this->intArg($value, 0);
        if ($max === null) {
            $this->bail();

            return;
        }

        $this->limit->maxAttempts = $max;
        $this->limit->decaySeconds = self::WINDOWS[$method];
    }

    /** The nth descriptor argument as a literal int; null when absent or non-literal. */
    private function intArg(ConstValue $descriptor, int $index): ?int
    {
        $arg = $descriptor->args[$index] ?? null;

        return $arg instanceof ConstValue && $arg->isScalar() && is_int($arg->scalar) ? $arg->scalar : null;
    }

    private function bail(): void
    {
        $this->limit->bailed = true;
        $this->limit->maxAttempts = null;
        $this->limit->decaySeconds = null;
    }
}
