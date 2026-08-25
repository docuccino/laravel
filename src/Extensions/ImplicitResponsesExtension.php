<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Provenance\Source;
use Docuccino\Laravel\Support\AuthMiddlewareDetector;
use Docuccino\Laravel\Support\IgnoredResponses;
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

        // 403 — authorization middleware or a FormRequest authorize() gate.
        $authorization = $this->authorizationSignal($context);
        if ($authorization !== null) {
            $this->synthesize($operation, $context, 403, self::AUTHORIZATION, $authorization);
        }
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
            if (str_starts_with($middleware, 'can:')) {
                return 'can-middleware';
            }
            if ($middleware === 'signed' || str_starts_with($middleware, 'signed:')) {
                return 'signed-middleware';
            }
            if ($middleware === 'verified' || str_starts_with($middleware, 'verified:')) {
                return 'verified-middleware';
            }
        }

        return $this->formRequestAuthorizes($context) ? 'formrequest-authorize' : null;
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

        $line = $method->getStartLine();
        $analysis = $context->engine->analyzeAction(new ActionRef($methodFile, $formRequest, 'authorize', $line === false ? 0 : $line));
        $context->recordDependencyFiles($analysis->dependencyFiles);

        // A `return true;` gate never fails, so no 403; anything else can deny. Unknown returns
        // document nothing — the 403 only appears when the engine can prove the gate isn't `true`.
        foreach ($analysis->returns as $return) {
            if (! ($return->type instanceof LiteralT && $return->type->value === true)) {
                return true;
            }
        }

        return false;
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
