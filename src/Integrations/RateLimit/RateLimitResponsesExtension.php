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
 * Documents a `429 Too Many Requests` (with `Retry-After` + `X-RateLimit-*` headers) on any operation
 * whose route carries a `throttle` middleware. Always on — `throttle` ships with Laravel.
 *
 * Numeric throttles (`throttle:60,1`) document the concrete limit. A named limiter (`throttle:api`) is
 * looked up in the booted app's `RateLimiter::for` registrations, its closure located by
 * `ReflectionFunction` and folded by {@see RateLimiterLimitVisitor}. A limiter that won't fold (dynamic,
 * conditional, custom-response) still gets a 429, just without numbers, plus an info diagnostic.
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
     * Folds a named limiter's `RateLimiter::for` closure to concrete numbers; if it's missing,
     * unregistered or too dynamic, keeps the numberless named limit and reports a diagnostic.
     */
    private function resolveNamedLimiter(ThrottleLimit $limit, RouteContext $context): ThrottleLimit
    {
        $name = (string) $limit->name;
        $closure = $this->limiters->limiter($name);

        if ($closure instanceof Closure) {
            $folded = $this->foldLimiter($closure, $context);
            if ($folded !== null) {
                return $folded; // numbers recovered, so no diagnostic
            }
        }

        $this->reportNamedLimiter($limit, $context, $closure !== null);

        return $limit;
    }

    /**
     * Reflects the limiter closure, traces it, and builds a numeric {@see ThrottleLimit} from a single
     * folded `Limit::per*(…)`. The closure's file becomes a fragment-cache dependency so editing the
     * limiter invalidates the doc. Null when it can't be reflected or doesn't fold cleanly.
     */
    private function foldLimiter(Closure $closure, RouteContext $context): ?ThrottleLimit
    {
        try {
            // `RateLimiter::limiter()` hands back a wrapper closure (it dedupes multi-limit keys) over
            // the app's real callback — unwrap one level so we trace user code, not framework glue.
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
