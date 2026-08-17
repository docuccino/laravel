<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ComponentDeclaration;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use ReflectionMethod;
use Throwable;

/**
 * The flagship tier of the error-response chain (design §6): documents the app's real error shapes by
 * analysing the code that actually renders each exception. Resolution order per thrown exception — a
 * render callback (`$exceptions->render(fn (T $e) => …)`) whose first-parameter type the exception `is_a`,
 * with the parameter narrowed to the thrown type so a catch-all `fn (Throwable $e)` resolves the one
 * reachable branch; then the exception's own `render()`; then a `Responsable`'s `toResponse()`.
 *
 * The recovered `JsonResponse<payload, status>` becomes the documented response, under the name the
 * render path declared with `#[ErrorComponent]` where one did. A body too dynamic to fold defers with an
 * info diagnostic so the next tier (preset, framework defaults) fills in. Ordered FIRST so ground truth
 * beats any preset — a mapper that must beat it says so with an order of its own. Handler files join the
 * route's fragment-cache deps.
 */
#[ExtensionOrder(priority: Priorities::FIRST)]
final class InferredHandlerExceptionToResponse implements ExceptionToResponse
{
    private const RESPONSABLE = 'Illuminate\\Contracts\\Support\\Responsable';

    /** @var array<string, CallableRef|null> memoised candidate per exception FQCN */
    private array $candidates = [];

    public function __construct(private readonly HandlerReflector $reflector) {}

    public function supports(ThrownException $exception, RouteContext $context): bool
    {
        return $this->candidate($exception->exceptionFqcn) !== null;
    }

    public function producer(): string
    {
        return 'integration:inferred-handler';
    }

    public function toResponse(
        ThrownException $exception,
        RouteContext $context,
        ComponentRegistry $components,
    ): ?ResponseDraft {
        // A `render()` added to a parent, or `Responsable` implemented up the chain, decides whether
        // this tier claims the exception. Recorded before the decline, so "no handler" goes stale too.
        $context->recordDependencyFiles(DeclarationFiles::of($exception->exceptionFqcn));

        $callable = $this->candidate($exception->exceptionFqcn);
        if ($callable === null) {
            return null;
        }

        $analysis = $context->engine->analyzeCallable($callable);
        // Cache soundness (design §10): editing the handler, or any helper its response is built through,
        // must invalidate this route's fragment.
        $context->recordDependencyFiles($analysis->dependencyFiles);
        $this->reportIllegalNames($analysis, $context, $components);

        $response = HandlerResponseBuilder::build(
            $analysis,
            $context,
            Contribution::integration('inferred-handler'),
            $exception->httpStatusHint,
        );
        if ($response !== null) {
            return $response;
        }

        // Nothing recovered. A framework delegation (`return null`/void arm) is expected, so defer
        // quietly; a real fold failure is noted per callback for one summary diagnostic at build. The note
        // goes on the ROUTE and not into the log the summary reads: it has to ride this route's fragment,
        // or a warm build comes back without the summary a cold one publishes ({@see HandlerDeferralLog}).
        if (! HandlerResponseBuilder::isDelegation($analysis)) {
            $context->notes()->record(HandlerDeferralLog::CHANNEL, $callable->target(), $exception->exceptionFqcn);
        }

        return null;
    }

    /**
     * A render method that declared a name no component key could carry. `claimComponentName()` drops
     * such a name at the write and says nothing, which leaves the author of the attribute with a line of
     * code that does nothing and no reason why — and this tier, unlike the draft, is handed the channel
     * to say it. Raised per throw rather than remembered per name: a tier instance outlives a build, and
     * a warm build that reported less than a cold one is a silent degradation, which repeating a line is
     * not.
     *
     * Within one analysis it IS one report per mistake, keyed by the mistake and sorted, the way the class
     * anchor keys its own: a renderer with three `return`s under one bad attribute is one typo, and saying
     * it three times says nothing more.
     */
    private function reportIllegalNames(ActionAnalysis $analysis, RouteContext $context, ComponentRegistry $components): void
    {
        /** @var array<string, ComponentDeclaration> $illegal */
        $illegal = [];
        foreach ($analysis->returns as $return) {
            $declaration = $return->component;
            if ($declaration === null || $components->isLegalName($declaration->name)) {
                continue;
            }

            $illegal[$declaration->symbol."\0".$declaration->name] = $declaration;
        }

        ksort($illegal);

        foreach ($illegal as $declaration) {
            $components->addDiagnostic(new Diagnostic(
                severity: Severity::Warning,
                code: 'attribute.error-component-invalid',
                message: sprintf(
                    '%s declares #[ErrorComponent("%s")], which is not a name an OpenAPI component key can carry, so the attribute names nothing and the response keeps the name it would have had.',
                    $declaration->symbol,
                    $declaration->name,
                ),
                source: $context->sourceAt($declaration->location, $declaration->symbol),
                routeSignature: $context->route->signature(),
                help: 'A component key is letters, digits, ".", "_" and "-" only. A reason phrase as one word — "NotFound", "TooManyRequests" — is what reads best as a generated client\'s type.',
            ));
        }
    }

    private function candidate(string $fqcn): ?CallableRef
    {
        if (array_key_exists($fqcn, $this->candidates)) {
            return $this->candidates[$fqcn];
        }

        return $this->candidates[$fqcn] = $this->resolve($fqcn);
    }

    private function resolve(string $fqcn): ?CallableRef
    {
        foreach ($this->reflector->renderCallbacks() as $callback) {
            if ($fqcn === $callback->exceptionType || is_a($fqcn, $callback->exceptionType, true)) {
                // Narrowing the parameter to the thrown type is a no-op for an exactly-typed callback and
                // branch selection for a catch-all. Method-backed callbacks are analysed as the real
                // method; a genuine closure is located by line.
                return $callback->isMethod()
                    ? new CallableRef($callback->file, $callback->class, $callback->method, 0, $callback->parameterName, $fqcn)
                    : new CallableRef($callback->file, null, null, $callback->line, $callback->parameterName, $fqcn);
            }
        }

        return $this->renderableMethod($fqcn, 'render')
            ?? ($this->isResponsable($fqcn) ? $this->renderableMethod($fqcn, 'toResponse') : null);
    }

    /** A ref to a `render()`/`toResponse()` the exception declares itself, if it has a reflectable one. */
    private function renderableMethod(string $fqcn, string $method): ?CallableRef
    {
        try {
            if (! class_exists($fqcn) || ! method_exists($fqcn, $method)) {
                return null;
            }

            $reflection = new ReflectionMethod($fqcn, $method);
            $file = $reflection->getFileName();
            if ($file === false) {
                return null;
            }

            // Analyse the method on the class that declares it — its real source location.
            return new CallableRef($file, $reflection->getDeclaringClass()->getName(), $method);
        } catch (Throwable) {
            return null;
        }
    }

    private function isResponsable(string $fqcn): bool
    {
        return interface_exists(self::RESPONSABLE) && is_a($fqcn, self::RESPONSABLE, true);
    }
}
