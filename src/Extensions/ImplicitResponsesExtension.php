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
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Provenance\Source;
use Docuccino\Laravel\Support\CanGate;
use Docuccino\Laravel\Support\FormRequestFailure;
use Docuccino\Laravel\Support\GateBody;
use Docuccino\Laravel\Support\GateDenial;
use Docuccino\Laravel\Support\IgnoredResponses;
use Docuccino\Laravel\Support\MiddlewareClasses;
use Docuccino\Laravel\Support\RouteAuthentication;
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
 *  | 401    | the route runs an authenticator ({@see RouteAuthentication}) AND is not `#[Unauthenticated]` |
 *  | 422    | validation rules were recovered (Data / FormRequest / inline / action rules()), body or query |
 *  | 404    | the route has ≥1 model-bound path parameter (one 404 per operation, not per param) |
 *  | 403    | `can:` / `signed` / `verified` middleware, an auth package's scope or ability middleware naming one, spatie/laravel-permission's role or permission middleware, or a FormRequest `authorize()` not `return true` |
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

    private const VALIDATION = 'Illuminate\\Validation\\ValidationException';

    private const MODEL_NOT_FOUND = 'Illuminate\\Database\\Eloquent\\ModelNotFoundException';

    private const AUTHORIZATION = 'Illuminate\\Auth\\Access\\AuthorizationException';

    public function __construct(
        private readonly GateDenial $gates,
        private readonly MiddlewareClasses $middleware,
        private readonly RouteAuthentication $authentication,
        private readonly ResponseDraftApplier $applier = new ResponseDraftApplier,
        private readonly InferredResponsesExtension $responses = new InferredResponsesExtension,
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

        // 401 — behind an authenticator and not explicitly public, as whatever that authenticator throws.
        $refusal = $this->authentication->refusal($context);
        if ($refusal !== null && ! $context->attributes->has(Unauthenticated::class)) {
            $this->synthesize($operation, $context, 401, $refusal, 'auth-middleware');
        }

        // 422 — the route validates its input, in the body or in the query.
        if ($this->hasValidatedRequest($operation)) {
            $this->refuse($operation, $context, 422, self::VALIDATION, 'validated-request', FormRequestFailure::VALIDATION);
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
            if ($authorization['signal'] === 'formrequest-authorize') {
                $this->refuse($operation, $context, 403, $authorization['exception'], $authorization['signal'], FormRequestFailure::AUTHORIZATION);
            } else {
                $this->synthesize($operation, $context, 403, $authorization['exception'], $authorization['signal']);
            }

            if ($noOther403 && $operation->hasResponse('403')) {
                $this->reportUndeniableGates($context, $authorization['signal']);
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
                if ($this->middlewareSignal($context, $middleware) !== null) {
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

    /**
     * The response a request the route's FormRequest refuses gets: what the application's override of the
     * refusing hook answers with where it has one ({@see FormRequestFailure}), and the framework's error where it
     * has none. The override replaces that error outright — a response it throws is documented as a returned one
     * is, at the status it carries, and an exception it throws as an action's throw is — so the framework's body
     * stands only where the override is the framework's own (`parent::` is read as the exception it declares) or
     * where nothing it does could be read, which the author is told about.
     */
    private function refuse(
        OperationDraft $operation,
        RouteContext $context,
        int $status,
        string $exceptionFqcn,
        string $signal,
        string $hook,
    ): void {
        $failure = FormRequestFailure::of($context, $hook);
        if ($failure === null || ! $failure->isRead()) {
            $this->synthesize($operation, $context, $status, $exceptionFqcn, $signal);
            if ($failure !== null) {
                $this->reportUnreadFailure($context, $failure, $status);
            }

            return;
        }

        $this->responses->document($operation, $context, $failure->responses, null, $failure->label);

        foreach ($failure->throws as $throw) {
            $mapped = IgnoredResponses::mapThrow($context, $throw);
            if ($mapped !== null) {
                $this->applier->apply($operation, $mapped->draft, $mapped->producer(), $this->throwSource($context, $throw));
            }
        }
    }

    private function reportUnreadFailure(RouteContext $context, FormRequestFailure $failure, int $status): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Warning,
            code: 'inferred-response.form-request-failure-unread',
            message: sprintf(
                '%s answers the request it refuses in a way this build cannot read, so the framework\'s %d is documented in its place, which may not be the response the server sends.',
                $failure->label,
                $status,
            ),
            routeSignature: $context->route->signature($context->httpMethod()),
            help: sprintf(
                'Throw an HttpResponseException carrying a response built where the analyzer can see it (response()->json([...], %d)), or an exception named at the throw (throw new SubmissionRefused(...)); or call parent::%s() where the framework\'s answer is the one you want.',
                $status,
                $failure->hook,
            ),
        ));
    }

    /** Where a refusal's throw is written: its deepest frame, else the action. */
    private function throwSource(RouteContext $context, ThrownException $throw): ?Source
    {
        $frame = $throw->callChain === [] ? null : $throw->callChain[count($throw->callChain) - 1];

        return $frame !== null && $frame->location->file !== ''
            ? $context->sourceAt($frame->location, $throw->exceptionFqcn)
            : $context->actionSource();
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
     * True when the route validates its input: something declared so on the draft
     * ({@see OperationDraft::declareValidatesInput()}), as every recovered rule set does whether it became
     * a body or — on a read verb — query parameters. A parameter or body that is only ever declared by
     * attribute rightly earns nothing.
     *
     * A body is also tested by layer, not by a closed producer list: an `integration:*` producer on
     * `requestBody` means some request recoverer built the body, so a third-party recoverer earns the 422
     * too. The whole trail is read, not just the winner: `requestBody` is one field written whole, so a
     * `#[BodyParameter]` patching one property of a recovered body takes the field at the attribute
     * layer — and the route still validates. Query parameters get no layer test, because pagination and
     * Query Builder write them at the integration layer without validating anything.
     */
    private function hasValidatedRequest(OperationDraft $operation): bool
    {
        if ($operation->validatesInput()) {
            return true;
        }

        foreach ($operation->producersFor('requestBody') as $producer) {
            if (str_starts_with($producer, 'integration:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The 403 signal and the exception it stands for, or null. Authorization middleware first, then a
     * FormRequest whose `authorize()` gate is not a literal `return true`.
     *
     * @return array{signal: string, exception: string}|null
     */
    private function authorizationSignal(RouteContext $context): ?array
    {
        foreach ($context->route->middleware as $middleware) {
            $signal = $this->middlewareSignal($context, $middleware);
            if ($signal !== null) {
                return $signal;
            }
        }

        return $this->formRequestAuthorizes($context) ? ['signal' => 'formrequest-authorize', 'exception' => self::AUTHORIZATION] : null;
    }

    /**
     * The 403 signal ONE middleware raises, or null. Stated once because two readers ask it: the signal
     * above takes the first answer in route order, while the reachability check needs to know whether
     * anything OTHER than a `can:` gate is also holding the 403 up.
     *
     * @return array{signal: string, exception: string}|null
     */
    private function middlewareSignal(RouteContext $context, string $middleware): ?array
    {
        if (CanGate::matches($middleware)) {
            return ['signal' => 'can-middleware', 'exception' => self::AUTHORIZATION];
        }
        // Asked by class, through the build's alias map: an application's own alias or subclass of either
        // middleware produces the 403 too, and an alias re-pointed elsewhere does not.
        if ($this->middleware->runs($context, $middleware, ValidateSignature::class)) {
            return ['signal' => 'signed-middleware', 'exception' => self::AUTHORIZATION];
        }
        if ($this->middleware->runs($context, $middleware, EnsureEmailIsVerified::class)) {
            return ['signal' => 'verified-middleware', 'exception' => self::AUTHORIZATION];
        }

        // A user or token lacking what a package's middleware names, as the package throws it.
        return $this->authentication->denial($context, $middleware);
    }

    /** Whether the route's FormRequest declares an authorize() gate that is not a literal `return true`. */
    private function formRequestAuthorizes(RouteContext $context): bool
    {
        $formRequest = $context->formRequestClass;
        if ($formRequest === null || ! class_exists($formRequest)) {
            return false;
        }

        $reflection = new ReflectionClass($formRequest);

        // Record the files BEFORE the method-presence bail: adding an authorize() gate to a warm-cached
        // route's FormRequest has to invalidate its fragment (design §10), and the gate can be added to
        // a base class or a trait as readily as to the request itself.
        $context->recordDependencyFiles(DeclarationFiles::of($formRequest));

        // The framework declares no authorize() anywhere — `passesAuthorization()` calls one only if
        // `method_exists` — so having the method AT ALL is having a gate somebody wrote, and where they
        // wrote it says nothing about whose it is. An application's shared base or trait is a normal
        // place for one, and reading those as "no gate" drops the 403 the endpoint really returns.
        if (! $reflection->hasMethod('authorize')) {
            return false;
        }

        $method = $reflection->getMethod('authorize');
        $methodFile = $method->getFileName();
        if ($methodFile === false) {
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
