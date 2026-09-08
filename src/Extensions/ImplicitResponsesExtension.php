<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Provenance\Source;
use Docuccino\Laravel\Support\AuthMiddlewareDetector;
use Docuccino\Laravel\Support\CanGate;
use Docuccino\Laravel\Support\GateBody;
use Docuccino\Laravel\Support\GateDenial;
use Docuccino\Laravel\Support\IgnoredResponses;
use Docuccino\Laravel\Support\MiddlewareName;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Routing\Middleware\ValidateSignature;
use ReflectionClass;

/**
 * Synthesizes the error responses that middleware and binding-time machinery produce but the action
 * body never throws, so throw analysis can't see them (design §Errors). Each signal becomes a
 * synthetic {@see ThrownException} run through the same exception→response chain as an explicit
 * throw, so the body matches the document's error style:
 *
 *  | Status | Signal |
 *  |--------|--------|
 *  | 401    | auth middleware detected AND the route is not `#[Unauthenticated]` |
 *  | 422    | a validated request body was recovered (Data / FormRequest / action rules()) |
 *  | 404    | the route has ≥1 model-bound path parameter (one 404 per operation, not per param) |
 *  | 403    | `can:` / `signed` / `verified` middleware, or a FormRequest `authorize()` not `return true` |
 *
 * The 403 is inferred from the PRESENCE of a gate, which is right for `signed`/`verified` and only
 * usually right for `can:`: a policy method that returns `true` unconditionally cannot deny, and the
 * error is then one no request can provoke. Where this is the operation's ONLY 403 and every `can:`
 * gate on the route is that shape, the response still publishes — a diagnostic can never drop a real
 * error, and dropping this one would need certainty a build does not have — and
 * `authorization.gate-cannot-deny` says so ({@see GateDenial} for how narrow "cannot deny" is).
 *
 * Runs LATE in the Errors phase at integration precedence, so an exception the action also throws
 * explicitly ({@see ErrorResponsesExtension}) owns its status and shadows the synthesis — no double
 * response. Docblock/attribute/overlay override it, each status honours `#[IgnoreResponse]`, and
 * `error_responses => 'none'` skips it. 429 belongs to the rate-limit integration; CSRF 419,
 * maintenance 503 and custom-middleware throws are non-goals.
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class ImplicitResponsesExtension implements OperationExtension
{
    private const PRODUCER = 'integration:implicit-response';

    private const AUTHENTICATION = 'Illuminate\\Auth\\AuthenticationException';

    private const VALIDATION = 'Illuminate\\Validation\\ValidationException';

    private const MODEL_NOT_FOUND = 'Illuminate\\Database\\Eloquent\\ModelNotFoundException';

    private const AUTHORIZATION = 'Illuminate\\Auth\\Access\\AuthorizationException';

    public function __construct(
        private readonly GateDenial $gates,
        private readonly ResponseDraftApplier $applier = new ResponseDraftApplier,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Errors;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->document->errorResponses === 'none') {
            return;
        }

        // 401 — behind auth middleware and not explicitly public.
        if (AuthMiddlewareDetector::matches($context) && ! $context->attributes->has(Unauthenticated::class)) {
            $this->synthesize($operation, $context, 401, self::AUTHENTICATION, 'auth-middleware');
        }

        // 422 — a validated request body was recovered for a write verb.
        if ($this->hasValidatedRequest($operation)) {
            $this->synthesize($operation, $context, 422, self::VALIDATION, 'validated-request');
        }

        // 404 — one per operation, regardless of how many params are model-bound.
        if ($context->routeBindings !== []) {
            $this->synthesize($operation, $context, 404, self::MODEL_NOT_FOUND, 'route-model-binding');
        }

        // 403 — authorization middleware or a FormRequest authorize() gate. Whether the operation
        // already carries one is read BEFORE the synthesis: every other producer of a 403 — an explicit
        // throw, an attribute, an action's authorize() — has run by now, and their 403 is reachable
        // whatever the gates say.
        $authorization = $this->authorizationSignal($context);
        if ($authorization !== null) {
            $noOther403 = ! $operation->hasResponse('403');
            $this->synthesize($operation, $context, 403, self::AUTHORIZATION, $authorization);

            if ($noOther403 && $operation->hasResponse('403')) {
                $this->reportUndeniableGates($context, $authorization);
            }
        }
    }

    /**
     * Reports a published 403 that no gate on the route can produce. Silent unless the gates are the
     * WHOLE story: a `signed`/`verified` middleware or a FormRequest gate denies on its own, and the
     * signal name is what says which of them the 403 came from.
     */
    private function reportUndeniableGates(RouteContext $context, string $signal): void
    {
        if ($signal !== 'can-middleware' || $this->formRequestAuthorizes($context)) {
            return;
        }

        $findings = [];
        foreach ($context->route->middleware as $middleware) {
            $gate = CanGate::parse($middleware);
            if ($gate === null) {
                // Two shapes reach here having already accounted for the 403: a `signed` or `verified`
                // middleware, which denies on its own; and the authorization middleware naming no
                // ability, which {@see CanGate::matches()} answers to and which denies every request
                // that meets it because no policy stands behind it. Returning on the second is also
                // what leaves the report below at least one finding to name.
                if (self::middlewareSignal($middleware) !== null) {
                    return;
                }

                continue;
            }

            $policyMethod = $this->gates->undeniablePolicyMethod($context, $gate);
            if ($policyMethod === null) {
                // One gate that can deny — or that could not be resolved — makes the 403 reachable.
                return;
            }

            $findings[] = $gate->describe().', but '.$policyMethod.'() returns true unconditionally';
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'authorization.gate-cannot-deny',
            message: count($findings) === 1
                ? sprintf('Publishes a 403 from %s, so the gate cannot deny.', $findings[0])
                : sprintf('Publishes a 403 from %d ->can() gates — %s — so no gate on this route can deny.', count($findings), implode('; ', $findings)),
            routeSignature: $context->route->signature($context->httpMethod()),
            help: 'Drop the response with #[IgnoreResponse(403)] on the action if the gate is meant to be a formality, or tighten the policy method so the 403 it publishes is one a request can provoke.',
        ));
    }

    private function synthesize(
        OperationDraft $operation,
        RouteContext $context,
        int $status,
        string $exceptionFqcn,
        string $signal,
    ): void {
        $throw = new ThrownException($exceptionFqcn, $status, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
        $source = $this->signalSource($context, $signal);

        // The ignore is read off the MAPPED status rather than off the synthetic one, so a mapper that
        // answers this signal somewhere else is dropped by an attribute naming where it really landed
        // ({@see IgnoredResponses::mapThrow()}).
        $mapped = IgnoredResponses::mapThrow($context, $throw);
        if ($mapped !== null) {
            $this->applier->apply($operation, $mapped->draft, self::PRODUCER, $source);
        }
    }

    /**
     * True when a request extension recovered a validated body. Tested by layer, not by a closed
     * producer list: an `integration:*` producer on `requestBody` means some request recoverer built
     * the body, so third-party recoverers earn the 422 too — while a body that is only ever
     * `#[BodyParameter]` rightly doesn't.
     *
     * The whole trail is read, not just the winner. `requestBody` is one field written whole, so a
     * `#[BodyParameter]` patching one property of a recovered body takes the field at the attribute
     * layer — and the route still validates. Asking the winner alone made the 422 depend on whether an
     * unrelated attribute happened to be present.
     */
    private function hasValidatedRequest(OperationDraft $operation): bool
    {
        foreach ($operation->producersFor('requestBody') as $producer) {
            if (str_starts_with($producer, 'integration:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The 403 signal name, or null. Authorization middleware first (`can:`/`signed`/`verified`), then
     * a FormRequest whose `authorize()` gate is not a literal `return true`.
     */
    private function authorizationSignal(RouteContext $context): ?string
    {
        foreach ($context->route->middleware as $middleware) {
            $signal = self::middlewareSignal($middleware);
            if ($signal !== null) {
                return $signal;
            }
        }

        return $this->formRequestAuthorizes($context) ? 'formrequest-authorize' : null;
    }

    /**
     * The 403 signal ONE middleware raises, or null. Stated once because two readers ask it: the signal
     * above takes the first answer in route order, while the reachability check needs to know whether
     * anything OTHER than a `can:` gate is also holding the 403 up.
     */
    private static function middlewareSignal(string $middleware): ?string
    {
        if (CanGate::matches($middleware)) {
            return 'can-middleware';
        }
        // Each of these has the same two spellings the authorization middleware does, and the
        // class-name one is what the framework's own static constructors write —
        // `ValidateSignature::relative()` and `EnsureEmailIsVerified::redirectTo($route)`. Reading only
        // the alias missed a middleware that really does produce the 403, which is worse than a missed
        // signal: the reachability check then reports a route whose 403 the signature genuinely denies.
        if (MiddlewareName::matches($middleware, 'signed', ValidateSignature::class)) {
            return 'signed-middleware';
        }
        if (MiddlewareName::matches($middleware, 'verified', EnsureEmailIsVerified::class)) {
            return 'verified-middleware';
        }

        return null;
    }

    /** Whether the route's FormRequest declares an authorize() gate that is not a literal `return true`. */
    private function formRequestAuthorizes(RouteContext $context): bool
    {
        $formRequest = $context->formRequestClass;
        if ($formRequest === null || ! class_exists($formRequest)) {
            return false;
        }

        $reflection = new ReflectionClass($formRequest);

        // Record the file BEFORE the method-presence bail: adding an authorize() gate to a warm-cached
        // route's FormRequest has to invalidate its fragment (design §10).
        $formRequestFile = $reflection->getFileName();
        if ($formRequestFile !== false) {
            $context->recordDependencyFiles([$formRequestFile]);
        }

        if (! $reflection->hasMethod('authorize')) {
            return false;
        }

        $method = $reflection->getMethod('authorize');
        $methodFile = $method->getFileName();
        // Only an authorize() in the FormRequest's own file is a real gate — an inherited framework
        // default isn't.
        if ($methodFile === false || $methodFile !== $reflection->getFileName()) {
            return false;
        }

        // The engine's answer alone, and an unread body is NOT a gate here: the 403 only appears where
        // the engine could prove the gate is something other than `true`, which is the opposite default
        // to the one the `can:` path states for the same three-valued answer ({@see GateBody}).
        return GateBody::analysed($context, $method, $methodFile) === GateBody::CanDeny;
    }

    private function signalSource(RouteContext $context, string $signal): Source
    {
        $base = $context->actionSource();
        if ($base === null) {
            return new Source('', null, 'implicit:'.$signal);
        }

        return new Source($base->file, $base->line, 'implicit:'.$signal);
    }
}
