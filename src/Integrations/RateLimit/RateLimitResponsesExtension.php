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
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
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
 *
 * The body comes from the error-response chain rather than this integration — see {@see body()} for why a
 * middleware-synthesized response has to ask, and why it stays inline.
 */
final class RateLimitResponsesExtension implements OperationExtension
{
    /** What ThrottleRequests throws — the exception whose documented body this 429 must match. */
    private const THROTTLE_EXCEPTION = 'Illuminate\\Http\\Exceptions\\ThrottleRequestsException';

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

        $content = $this->body($context) ?? $built['content'];
        if (is_array($content)) {
            foreach ($content as $mediaType => $media) {
                $schema = is_array($media) && is_array($media['schema'] ?? null) ? $media['schema'] : [];
                foreach ($schema as $keyword => $value) {
                    if ($keyword === 'x-docuccino') {
                        continue;
                    }
                    $response->content((string) $mediaType)->set((string) $keyword, $value, $contribution);
                }

                if (is_array($media) && array_key_exists('example', $media)) {
                    $response->setExample((string) $mediaType, $media['example']);
                }
            }
        }
    }

    /**
     * The 429 body the document's own error style calls for, or null to keep the stock `{message}`.
     *
     * This 429 is synthesized from middleware rather than from a throw the engine saw, so the
     * error-response chain never gets asked about it — and hardcoding Laravel's shape would contradict an
     * app whose handler renders `application/problem+json` for the very same exception. Asking the chain
     * fixes that. The response stays inline rather than `$ref`-ing a shared component, because the
     * `X-RateLimit-*` headers alongside it are per-route values a shared response can't carry; when a
     * preset answers with a reference, the referenced component's content is copied in instead.
     *
     * Asking is a read, so the shared response the mapper registers is rolled back — this operation
     * `$ref`s nothing, and an unreferenced component would make a cold build's bytes differ from a
     * warm one's. Any schema the copied content points at stays registered.
     *
     * @return array<array-key, mixed>|null
     */
    private function body(RouteContext $context): ?array
    {
        if ($context->document->errorResponses === 'none') {
            return null;
        }

        $snapshot = $context->components->snapshot();

        try {
            $mapped = $context->mapThrow(new ThrownException(
                self::THROTTLE_EXCEPTION,
                429,
                [],
                ThrowConfidence::Certain,
                ThrowDisposition::Signal,
            ));
            if ($mapped === null) {
                return null;
            }

            $frozen = $mapped->draft->freeze();
            if ($frozen->content !== null && $frozen->content !== []) {
                return $frozen->content;
            }

            return $frozen->ref === null ? null : self::referencedContent($frozen->ref, $context);
        } finally {
            $context->components->restoreResponses($snapshot);
        }
    }

    /**
     * The `content` of a `#/components/responses/*` the chain referenced, or null when the pointer names
     * something that isn't a registered response with a body.
     *
     * @return array<array-key, mixed>|null
     */
    private static function referencedContent(string $ref, RouteContext $context): ?array
    {
        $prefix = '#/components/responses/';
        if (! str_starts_with($ref, $prefix)) {
            return null;
        }

        $component = $context->components->responses()[substr($ref, strlen($prefix))] ?? null;
        $content = is_array($component) ? ($component['content'] ?? null) : null;

        return is_array($content) && $content !== [] ? $content : null;
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
