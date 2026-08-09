<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

use Closure;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Patch\Contribution;
use Illuminate\Cache\RateLimiter;
use ReflectionFunction;
use Throwable;

/**
 * Documents a `429 Too Many Requests` response (with `Retry-After` + `X-RateLimit-*` headers) on any
 * operation whose route carries a `throttle` middleware (design §Phase 4 — rate limiting). Numeric
 * throttles (`throttle:60,1`) document the concrete limit; a named limiter (`throttle:api`) is
 * introspected against the booted app's `RateLimiter::for` registrations. Its closure is located by
 * `ReflectionFunction` and handed to the engine's closure trace, which folds a single-return
 * `Limit::per*(…)` to concrete numbers ({@see RateLimiterLimitVisitor}); a limiter that cannot be
 * folded (dynamic, conditional, or custom-response) still documents the 429, but without numbers,
 * plus an info diagnostic. Always-on: `throttle` ships with Laravel.
 */
final class RateLimitResponsesExtension implements OperationExtension
{
    public function __construct(
        private readonly RateLimiter $limiters,
        private readonly ThrottleParser $parser = new ThrottleParser,
        private readonly RateLimitResponse $response = new RateLimitResponse,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Responses;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $limits = $this->throttles($context);
        if ($limits === []) {
            return;
        }

        $limit = $limits[0];

        if (count($limits) > 1) {
            $this->reportMultiple($limits, $context);
        }

        if ($limit->isNamed()) {
            $limit = $this->resolveNamedLimiter($limit, $context);
        }

        $contribution = Contribution::integration('rate-limit', $context->actionSource());
        $response = $operation->response('429');

        $built = $this->response->build($limit);

        $description = $built['description'];
        if (is_string($description)) {
            $response->setDescription($description, $contribution);
        }
        $response->set('headers', $built['headers'], $contribution);

        $content = $built['content'];
        if (is_array($content)) {
            foreach ($content as $mediaType => $media) {
                $schema = is_array($media) && is_array($media['schema'] ?? null) ? $media['schema'] : [];
                foreach ($schema as $keyword => $value) {
                    $response->content((string) $mediaType)->set((string) $keyword, $value, $contribution);
                }
            }
        }
    }

    /**
     * @return list<ThrottleLimit>
     */
    private function throttles(RouteContext $context): array
    {
        $limits = [];
        foreach ($context->route->middleware as $middleware) {
            $limit = $this->parser->parse($middleware);
            if ($limit !== null) {
                $limits[] = $limit;
            }
        }

        return $limits;
    }

    /**
     * @param  list<ThrottleLimit>  $limits
     */
    private function reportMultiple(array $limits, RouteContext $context): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'rate-limit.multiple-throttles',
            message: sprintf(
                'Route carries %d throttle middleware; a single 429 is documented from the first — the others are enforced independently but not separately represented.',
                count($limits),
            ),
            routeSignature: $context->route->signature(),
        ));
    }

    /**
     * Resolve a named limiter (`throttle:api`) to a concrete numeric limit by folding its
     * `RateLimiter::for` closure, or — when the closure is missing, unregistered, or too dynamic to
     * fold — report the info diagnostic and keep the numberless named limit (today's floor).
     */
    private function resolveNamedLimiter(ThrottleLimit $limit, RouteContext $context): ThrottleLimit
    {
        $name = (string) $limit->name;
        $closure = $this->limiters->limiter($name);

        if ($closure instanceof Closure) {
            $folded = $this->foldLimiter($closure, $context);
            if ($folded !== null) {
                return $folded; // numbers recovered — no diagnostic, the 429 carries concrete values
            }
        }

        $this->reportNamedLimiter($limit, $context, $closure !== null);

        return $limit;
    }

    /**
     * Locate the limiter closure by `ReflectionFunction`, hand it to the engine's closure trace, and
     * build a numeric {@see ThrottleLimit} from a single folded `Limit::per*(…)` — recording the
     * closure's file as a fragment-cache dependency so editing the limiter invalidates the doc.
     * Returns null when the closure cannot be reflected or does not fold to a single clean limit.
     */
    private function foldLimiter(Closure $closure, RouteContext $context): ?ThrottleLimit
    {
        try {
            // Laravel's `RateLimiter::limiter()` returns a wrapper closure (it dedupes multi-limit
            // keys) that closes over the app's actual limiter callback — unwrap one level so it is
            // the user's registered closure that gets reflected and traced, not framework glue.
            $reflection = new ReflectionFunction($closure);
            foreach ($reflection->getClosureUsedVariables() as $used) {
                if ($used instanceof Closure) {
                    $reflection = new ReflectionFunction($used);
                    break;
                }
            }
        } catch (Throwable) {
            return null;
        }

        $file = $reflection->getFileName();
        $line = $reflection->getStartLine();
        if ($file === false || $line === false) {
            return null;
        }

        $visitor = new RateLimiterLimitVisitor;
        $report = $context->engine->trace(new ActionRef($file, null, '{closure}', $line), $visitor);
        $context->recordDependencyFiles($report->dependencyFiles);

        $folded = $visitor->limit;
        if (! $folded->resolved()) {
            return null;
        }

        return new ThrottleLimit(maxAttempts: $folded->maxAttempts, decaySeconds: $folded->decaySeconds);
    }

    private function reportNamedLimiter(ThrottleLimit $limit, RouteContext $context, bool $registered): void
    {
        $name = (string) $limit->name;

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'rate-limit.dynamic-limit',
            message: $registered
                ? sprintf('Named rate limiter "%s" is registered but its limit is defined by a closure; the 429 is documented without numeric values.', $name)
                : sprintf('Rate limiter "%s" has no matching RateLimiter::for registration; the 429 is documented without numeric values.', $name),
            routeSignature: $context->route->signature(),
        ));
    }
}
