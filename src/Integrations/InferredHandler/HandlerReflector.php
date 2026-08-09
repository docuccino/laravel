<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionObject;
use Throwable;

/**
 * Reflects the booted app's exception handler for the callbacks it registered via
 * `$exceptions->render(…)` (`Illuminate\Foundation\Exceptions\Handler::$renderCallbacks`), catching
 * provider- and package-registered handlers a static AST scan would miss (design §6). Memoised: the
 * handler is reflected once per build, and each callback's source location + first-parameter type feed
 * the engine.
 *
 * Two shapes it must not miss. Decorated handlers: in console (where the export command runs) Collision
 * rebinds the handler to its own decorator, which holds the real Foundation handler in a property and has
 * no `renderCallbacks` of its own — {@see unwrap()} walks the chain. Method-backed callbacks:
 * `Handler::renderable()` puts every non-Closure callable through `Closure::fromCallable()`, so an
 * invokable renderer, an `[$obj, 'method']` pair or a first-class callable all arrive as closures naming a
 * real method, and must be analysed as that method — their declaration line isn't a closure literal, so
 * the closure-by-line path finds nothing there.
 *
 * A callback that's present but not analysable (no params, builtin first param, bound free function) goes
 * into {@see skipped()} rather than vanishing. Every step is defensive: an unexpected handler shape yields
 * no callbacks instead of failing the build.
 */
final class HandlerReflector
{
    /** Depth guard for the decoration walk — a handful of decorators at most, never a cycle. */
    private const MAX_UNWRAP_DEPTH = 5;

    /** @var list<RenderCallback>|null */
    private ?array $callbacks = null;

    /** @var list<string> the labels of registered callbacks that could not be resolved to an analysable form */
    private array $skipped = [];

    private bool $discovered = false;

    public function __construct(private readonly ExceptionHandler $handler) {}

    /**
     * Registration order, which is also Laravel's match order — first callback whose first-parameter type
     * the exception `is_a` wins.
     *
     * @return list<RenderCallback>
     */
    public function renderCallbacks(): array
    {
        $this->discover();

        return $this->callbacks ?? [];
    }

    /**
     * @return list<string>
     */
    public function skipped(): array
    {
        $this->discover();

        return $this->skipped;
    }

    private function discover(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        try {
            $handler = $this->unwrap($this->handler);
            if ($handler === null) {
                return;
            }

            $value = (new ReflectionObject($handler))->getProperty('renderCallbacks')->getValue($handler);
            if (! is_array($value)) {
                return;
            }

            $callbacks = [];
            foreach ($value as $callback) {
                if (! $callback instanceof Closure) {
                    $this->skipped[] = $this->describe($callback);

                    continue;
                }

                $resolved = $this->resolve($callback);
                if ($resolved !== null) {
                    $callbacks[] = $resolved;
                } else {
                    $this->skipped[] = $this->describe($callback);
                }
            }

            $this->callbacks = $callbacks;
        } catch (Throwable) {
            // Unexpected handler shape: leave the tier inert rather than fail the build.
        }
    }

    /**
     * The handler in the decoration chain that owns `renderCallbacks` — this handler, or the real
     * Foundation handler a decorator (Collision, Flare, …) holds as an {@see ExceptionHandler} property.
     */
    private function unwrap(ExceptionHandler $handler, int $depth = 0): ?ExceptionHandler
    {
        $reflection = new ReflectionObject($handler);
        if ($reflection->hasProperty('renderCallbacks')) {
            return $handler;
        }

        if ($depth >= self::MAX_UNWRAP_DEPTH) {
            return null;
        }

        foreach ($reflection->getProperties() as $property) {
            // Per-property, not per-walk: reading an uninitialized typed property throws, and one of those
            // anywhere in the chain would otherwise abort all discovery. Skip it and keep looking.
            try {
                $wrapped = $property->getValue($handler);
            } catch (Throwable) {
                continue;
            }

            if ($wrapped instanceof ExceptionHandler) {
                $found = $this->unwrap($wrapped, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function resolve(Closure $callback): ?RenderCallback
    {
        $function = new ReflectionFunction($callback);
        $parameters = $function->getParameters();
        $file = $function->getFileName();
        $line = $function->getStartLine();

        if ($parameters === [] || $file === false || $line === false) {
            return null;
        }

        $type = $parameters[0]->getType();
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $exceptionType = ltrim($type->getName(), '\\');
        $parameterName = $parameters[0]->getName();

        // Method-backed closure: analyse the real method, its declaration line isn't a closure literal. A
        // bound free function has no owning class to name — skip rather than mis-locate it.
        if (! $function->isAnonymous()) {
            $class = $function->getClosureScopeClass()?->getName();

            return $class === null
                ? null
                : new RenderCallback($file, $line, $parameterName, $exceptionType, $class, $function->getName());
        }

        return new RenderCallback($file, $line, $parameterName, $exceptionType);
    }

    private function describe(mixed $callback): string
    {
        if ($callback instanceof Closure) {
            $function = new ReflectionFunction($callback);

            return $function->isAnonymous()
                ? sprintf('closure@%s:%s', (string) $function->getFileName(), (string) $function->getStartLine())
                : sprintf('%s::%s', $function->getClosureScopeClass()?->getName() ?? '', $function->getName());
        }

        return is_object($callback) ? $callback::class : get_debug_type($callback);
    }
}
