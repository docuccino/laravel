<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\CallableRef;
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
 * The recovered `JsonResponse<payload, status>` becomes the documented response. A body too dynamic to
 * fold defers with an info diagnostic so the next tier (preset, framework defaults) fills in. Ordered
 * FIRST so ground truth beats any preset. Handler files join the route's fragment-cache deps.
 */
#[ExtensionOrder(priority: Priorities::FIRST)]
final class InferredHandlerExceptionToResponse implements ExceptionToResponse
{
    private const RESPONSABLE = 'Illuminate\\Contracts\\Support\\Responsable';

    /** @var array<string, CallableRef|null> memoised candidate per exception FQCN */
    private array $candidates = [];

    public function __construct(
        private readonly HandlerReflector $reflector,
        private readonly HandlerDeferralLog $deferrals,
    ) {}

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
        // quietly; a real fold failure is recorded per callback for one summary diagnostic at build.
        if (! HandlerResponseBuilder::isDelegation($analysis)) {
            $this->deferrals->record($callable->target(), $exception->exceptionFqcn);
        }

        return null;
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
