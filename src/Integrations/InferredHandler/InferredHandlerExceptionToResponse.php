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
use Docuccino\Laravel\Integrations\Support\AppRenderedErrors;
use ReflectionMethod;
use Throwable;

/**
 * The flagship tier of the error-response chain (design §6): documents the app's real error shapes by
 * analysing the code that actually renders each exception. Resolution order per thrown exception is
 * `Handler::render()`'s — the exception's own `render()`, looked past where it only returns null; then a
 * `Responsable`'s `toResponse()`; then the first render callback (`$exceptions->render(fn (T $e) => …)`)
 * whose first-parameter type the exception it is HANDED `is_a` ({@see ReceivedException}), with the
 * parameter narrowed to that class so a catch-all `fn (Throwable $e)` resolves the one reachable branch.
 *
 * The recovered `JsonResponse<payload, status>` becomes the documented response, under the name the
 * render path declared with `#[ErrorComponent]` where one did. A body too dynamic to fold raises one
 * `inferred-handler.too-dynamic` warning whether or not the tier still answers, and defers to the next
 * tier (framework defaults) only where nothing else folded ({@see HandlerResponseBuilder}). Ordered
 * FIRST so ground truth beats anything a document declares about its errors in the abstract — a mapper
 * that must beat it says so with an order of its own. Handler files join the route's fragment-cache deps.
 */
#[ExtensionOrder(priority: Priorities::FIRST)]
final class InferredHandlerExceptionToResponse implements ExceptionToResponse
{
    private const RESPONSABLE = 'Illuminate\\Contracts\\Support\\Responsable';

    /** @var array<string, list<CallableRef>> memoised candidates per exception FQCN */
    private array $candidates = [];

    public function __construct(private readonly HandlerReflector $reflector) {}

    public function supports(ThrownException $exception, RouteContext $context): bool
    {
        return $this->candidates($exception->exceptionFqcn) !== [];
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

        $candidates = $this->candidates($exception->exceptionFqcn);
        foreach ($candidates as $index => $callable) {
            $analysis = $context->engine->analyzeCallable($callable);
            // Cache soundness (design §10): editing the handler, or any helper its response is built through,
            // must invalidate this route's fragment.
            $context->recordDependencyFiles($analysis->dependencyFiles);
            HandlerResponseBuilder::reportIllegalNames($analysis, $context, $components);

            $response = HandlerResponseBuilder::build(
                $analysis,
                $context,
                Contribution::integration('inferred-handler'),
                $exception,
                $callable->target(),
            );
            if ($response !== null) {
                return $response;
            }

            // A renderer that only hands back null is looked past, as `Handler::render()` looks past it.
            $delegates = HandlerResponseBuilder::isDelegation($analysis);
            if ($delegates && $index < count($candidates) - 1) {
                continue;
            }

            // Declined, so the chain moves on — and the two notes below are both messages to what comes next.
            // The gate says the APPLICATION renders this exception and this build could not read what it
            // renders it to, which is exactly the question the tiers behind ask before publishing a body of
            // the framework's ({@see AppRenderedErrors}); recording it where the tier ANSWERED would write a
            // fact nothing can read, since no later tier is asked about an exception already answered for.
            // A framework delegation (`return null`/void arm) is neither: the framework really does render
            // those, so the gate stays open and the deferral goes unnoted. An analysis that recovered no
            // return at all refutes nothing either, though it is still a fold that failed.
            if ($analysis->returns !== [] && ! $delegates) {
                AppRenderedErrors::record($context, $exception->exceptionFqcn, $callable->target());
            }

            // The deferral is noted per callback for one summary diagnostic at build. The note goes on the
            // ROUTE and not into the log the summary reads: it has to ride this route's fragment, or a warm
            // build comes back without the summary a cold one publishes ({@see HandlerDeferralLog}).
            if (! $delegates) {
                HandlerDeferralLog::record($context, $callable->target(), $exception->exceptionFqcn);
            }

            return null;
        }

        return null;
    }

    /**
     * @return list<CallableRef> in the order `Handler::render()` tries them
     */
    private function candidates(string $fqcn): array
    {
        return $this->candidates[$fqcn] ??= $this->resolve($fqcn);
    }

    /**
     * @return list<CallableRef>
     */
    private function resolve(string $fqcn): array
    {
        $candidates = [];
        $own = $this->renderableMethod($fqcn, 'render');
        if ($own !== null) {
            $candidates[] = $own;
        }

        // A `Responsable` is always answered by its own `toResponse()`: no render callback is asked.
        if ($this->isResponsable($fqcn)) {
            $toResponse = $this->renderableMethod($fqcn, 'toResponse');

            return $toResponse === null ? $candidates : [...$candidates, $toResponse];
        }

        $received = ReceivedException::byRenderCallbacks($fqcn);
        foreach ($this->reflector->renderCallbacks() as $callback) {
            if ($received === $callback->exceptionType || is_a($received, $callback->exceptionType, true)) {
                // Narrowing the parameter to the received type is a no-op for an exactly-typed callback and
                // branch selection for a catch-all. Method-backed callbacks are analysed as the real
                // method; a genuine closure is located by line.
                $candidates[] = $callback->isMethod()
                    ? new CallableRef($callback->file, $callback->class, $callback->method, 0, $callback->parameterName, $received)
                    : new CallableRef($callback->file, null, null, $callback->line, $callback->parameterName, $received);

                break;
            }
        }

        return $candidates;
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
