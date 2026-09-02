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
use Docuccino\Laravel\Integrations\Support\AppRenderedErrors;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;
use Docuccino\Laravel\Support\IgnoredResponses;
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
 *
 * @phpstan-type ChainBody array{content: array<array-key, mixed>|null, placeholders: array<string, list<string>>}
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
        // A document that publishes no error responses publishes no 429 either. The 429 is an error
        // response synthesized from middleware rather than from a throw, but it is one — so it answers to
        // the document-level switch exactly as the implicit 401/403/404/422, the declared errors and the
        // Query Builder's 400 do, and the per-route knob for keeping one is `#[IgnoreResponse]`.
        if ($context->document->errorResponses === 'none') {
            return;
        }

        $limits = $this->throttles($context);
        if ($limits === []) {
            return;
        }

        $limit = $limits[0];

        // Above the ignore consult, because what it reports is an application bug rather than anything
        // about the documented response — see {@see checkRegistered()}. An author saying "do not document
        // the 429" has not said the limiter is registered.
        if ($limit->name !== null) {
            $this->checkRegistered($limit->name, $context);
        }

        // Asked before anything is built: the body below comes from the error-response chain, which
        // registers a shared response component as it goes ({@see IgnoredResponses}).
        if (IgnoredResponses::drops($context, '429')) {
            return;
        }

        // Below it, because this one reports what the DOCUMENT does with several throttles, and a route
        // that documents no 429 at all represents none of them to under-report.
        if (count($limits) > 1) {
            $this->reportMultiple($limits, $context);
        }

        $contribution = Contribution::integration('rate-limit', $context->actionSource());
        $response = $operation->response('429');
        // Every throttled route documents the same 429, headers and all, so the component it hoists into
        // can be named after the error rather than after the number.
        $response->claimComponentName(FrameworkExceptionTable::componentName('429'), $contribution, isStatusDefault: true);

        $built = $this->response->build();

        $description = $built['description'];
        if (is_string($description)) {
            $response->setDescription($description, $contribution);
        }
        $response->set('headers', $built['headers'], $contribution);

        // The stock `{message}` is the FRAMEWORK's shape, so it is withheld on the one route whose own
        // handler demonstrably renders the throttle exception and whose result the build could not read —
        // the same standing-aside the framework-defaults tier and the terminal fallback make off the same
        // fact ({@see AppRenderedErrors}). This is the fourth producer of a framework-shaped error body,
        // and filling the gap here would re-assert on a throttled route exactly what the other three
        // withheld everywhere else. The status, the reason and the rate headers all still answer.
        //
        // So a chain that ANSWERED is used as it stands, `content: null` included; only a chain with
        // nothing to say falls through to the framework shape.
        $chain = $this->body($context);
        $content = $chain === null ? $built['content'] : $chain['content'];
        $placeholders = $chain['placeholders'] ?? [];
        if (is_array($content)) {
            foreach ($content as $mediaType => $media) {
                // Registered first, for the reason core's response-draft merge states: a chain answer that
                // names a media type and constrains nothing under it is a representation this 429 really
                // has, and a copy driven by the keyword loop alone would drop it.
                $response->content((string) $mediaType);

                $schema = is_array($media) && is_array($media['schema'] ?? null) ? $media['schema'] : [];
                foreach ($schema as $keyword => $value) {
                    if ($keyword === 'x-docuccino') {
                        continue;
                    }
                    $response->content((string) $mediaType)->set((string) $keyword, $value, $contribution);
                }

                // With whichever of its members the chain FILLED rather than read, for the reason core's
                // response-draft merge states: the frozen body cannot say, and a copy that dropped the
                // set would publish a filled example claiming every member of it was proven.
                if (is_array($media) && array_key_exists('example', $media)) {
                    $response->setExample((string) $mediaType, $media['example'], $placeholders[(string) $mediaType] ?? []);
                }
            }
        }
    }

    /**
     * What the error-response chain says this 429's body is, or null where it said nothing usable — the
     * one answer that falls through to the framework's own shape. A chain that answered with NO body has
     * still answered: `content` is null and this 429 publishes no body, which is the whole point of
     * asking.
     *
     * This 429 is synthesized from middleware rather than from a throw the engine saw, so the
     * error-response chain never gets asked about it — and hardcoding Laravel's shape would contradict an
     * app whose handler renders `application/problem+json` for the very same exception. Asking the chain
     * fixes that. The response stays inline rather than `$ref`-ing the chain's component, because that
     * component carries none of the `X-RateLimit-*` headers this 429 needs; when a mapper answers with a
     * reference, the referenced component's content is copied in instead. The finished 429 — headers and
     * all — is then hoisted into its own shared component by `SharedErrorResponses`, which it can be
     * because the headers are identical for every throttled route.
     *
     * Asking is a read, so the shared response the mapper registers is rolled back — this operation
     * `$ref`s nothing, and an unreferenced component would make a cold build's bytes differ from a
     * warm one's. Any schema the copied content points at stays registered.
     *
     * A mapper's ROUTE NOTES roll back only where its answer is DISCARDED, which is the same rule
     * {@see IgnoredResponses::mapThrow()} states: a note written while building something nobody will see
     * is a fact about nothing and reaches the document as a diagnostic asking the author to fix it, while
     * a note about the body this 429 goes on to publish — or deliberately withholds — describes a
     * response the route really has.
     *
     * The filled members of each media type's example travel beside the content, since the chain's draft
     * is the only thing that knows them. A body reached through a `$ref` states none: what is copied there
     * is a component somebody else published, and this says no more about it than the document does.
     *
     * @return ChainBody|null
     */
    private function body(RouteContext $context): ?array
    {
        $components = $context->components->snapshot();
        $notes = $context->notes()->snapshot();
        $answer = null;

        try {
            $answer = $this->ask($context);
        } finally {
            $context->components->restoreResponses($components);
            if ($answer === null) {
                $context->notes()->restore($notes);
            }
        }

        return $answer;
    }

    /**
     * One consultation of the chain: its answer, or null where nothing usable came back.
     *
     * @return ChainBody|null
     */
    private function ask(RouteContext $context): ?array
    {
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
            $placeholders = [];
            foreach (array_keys($frozen->content) as $mediaType) {
                $filled = $mapped->draft->examplePlaceholders((string) $mediaType);
                if ($filled !== []) {
                    $placeholders[(string) $mediaType] = $filled;
                }
            }

            return ['content' => $frozen->content, 'placeholders' => $placeholders];
        }

        $referenced = $frozen->ref === null ? null : self::referencedContent($frozen->ref, $context);
        if ($referenced !== null) {
            return ['content' => $referenced, 'placeholders' => []];
        }

        // A tier answered and stated no body. Where that is the gate — the application renders this
        // exception itself and the build could not read what it renders it to — the answer IS the body
        // being withheld, and it is kept rather than rolled back.
        return AppRenderedErrors::includes($context, self::THROTTLE_EXCEPTION)
            ? ['content' => null, 'placeholders' => []]
            : null;
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
