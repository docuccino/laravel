<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;
use Illuminate\Cache\RateLimiter;

/**
 * Documents a `429 Too Many Requests` (with `Retry-After` + `X-RateLimit-*` headers) on any operation
 * whose route carries a `throttle` middleware. Always on — `throttle` ships with Laravel.
 *
 * The 429 itself is the same for every throttled route, limit values included — see
 * {@see RateLimitResponse} for why. A named limiter (`throttle:api`) is still looked up in the booted
 * app's `RateLimiter::for` registrations, but only to check that the registration exists — see
 * {@see checkRegistered()}.
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

        if ($limit->name !== null) {
            $this->checkRegistered($limit->name, $context);
        }

        $contribution = Contribution::integration('rate-limit', $context->actionSource());
        $response = $operation->response('429');
        // Every throttled route documents the same 429, headers and all, so the component it hoists into
        // can be named after the error rather than after the number.
        $response->claimComponentName(FrameworkExceptionTable::componentName('429'), $contribution);

        $built = $this->response->build();

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
     * fixes that. The response stays inline rather than `$ref`-ing the chain's component, because that
     * component carries none of the `X-RateLimit-*` headers this 429 needs; when a preset answers with a
     * reference, the referenced component's content is copied in instead. The finished 429 — headers and
     * all — is then hoisted into its own shared component by `SharedErrorResponses`, which it can be
     * because the headers are identical for every throttled route.
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
     * Reports a route throttling on a name nothing registered. That's an application bug rather than a
     * documentation one — Laravel's named-limiter lookup misses, `resolveMaxAttempts` falls through and
     * casts the name to `0` for a guest (so every request 429s) or reads it as a property off the user —
     * and it changes nothing about the documented 429. Saying so IS the whole point of the check.
     *
     * What a registered limiter's closure says is deliberately not read: the response is value-free, so a
     * dynamic limiter and a literal one document identically and there is nothing to report about either.
     */
    private function checkRegistered(string $name, RouteContext $context): void
    {
        if ($this->limiters->limiter($name) !== null) {
            return;
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'rate-limit.unregistered-limiter',
            message: sprintf('Route throttles on the named rate limiter "%s", but nothing registers it with RateLimiter::for().', $name),
            routeSignature: $context->route->signature(),
            help: sprintf("Register it in a service provider — RateLimiter::for('%s', fn (Request \$request) => Limit::perMinute(60)) — or state the allowance inline, as throttle:60,1.", $name),
        ));
    }
}
