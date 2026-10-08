<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Inference\CallableRef;
use ReflectionMethod;
use Throwable;

/**
 * The application's own renderers for one thrown type, in the order `Handler::render()` tries them — the
 * exception's own `render()`; then a `Responsable`'s `toResponse()`; then the first render callback whose
 * first-parameter type the exception it is HANDED `is_a` ({@see ReceivedException}), with the parameter
 * narrowed to that class so a catch-all `fn (Throwable $e)` resolves the one reachable branch. None means
 * the framework renders the exception itself.
 */
final class ExceptionRenderers
{
    private const RESPONSABLE = 'Illuminate\\Contracts\\Support\\Responsable';

    /** @var array<string, list<CallableRef>> memoised per exception FQCN */
    private array $renderers = [];

    public function __construct(private readonly HandlerReflector $reflector) {}

    /**
     * @return list<CallableRef>
     */
    public function of(string $fqcn): array
    {
        return $this->renderers[$fqcn] ??= $this->resolve($fqcn);
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
                // branch selection for a catch-all.
                $candidates[] = $callback->at->ref($callback->parameterName, $received);

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
