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
 * Reflects the BOOTED app's exception handler for the render callbacks it registered
 * (`$exceptions->render(…)` → `Illuminate\Foundation\Exceptions\Handler::$renderCallbacks`) —
 * catching provider- and package-registered handlers a static AST scan would miss (design §6
 * inferred-handler tier). The result is memoised: the handler is reflected once per build, and each
 * callback's source location + first-parameter type feed the engine's analysis.
 *
 * Two shapes it must not miss:
 *   - DECORATED handlers. In console (the export command runs there) Collision rebinds the handler to
 *     its own decorator, which holds the real Foundation handler in a property and exposes NO
 *     `renderCallbacks` of its own. {@see unwrap()} walks the decoration chain to the handler that owns
 *     the callbacks, so the export sees them too.
 *   - METHOD-BACKED callbacks. `Handler::renderable()` wraps every non-Closure render callable via
 *     `Closure::fromCallable()`, so an invokable renderer (`->render(new ProblemRenderer)`), an
 *     `[$obj, 'method']` pair, or a first-class callable all arrive as closures whose reflection names a
 *     real method — analysed as that method (its declaration line is not a closure literal, so the
 *     closure-by-line path would find nothing there).
 *
 * A callback present but not analysable (no params, builtin first param, a bound free function) is not
 * dropped in silence: it is recorded in {@see skipped()} so the tier can report it rather than fall
 * through unexplained. Every step is defensive — an unexpected handler shape yields no callbacks rather
 * than a failed build.
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
     * The registered render callbacks in registration order (the order Laravel itself matches them
     * in — first whose first-parameter type the exception `is_a` wins).
     *
     * @return list<RenderCallback>
     */
    public function renderCallbacks(): array
    {
        $this->discover();

        return $this->callbacks ?? [];
    }

    /**
     * Labels of registered render callbacks that were found on the handler but could not be resolved to
     * an analysable form — so the tier can surface an info diagnostic instead of silently ignoring them.
     *
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
            // An unexpected handler shape leaves the tier inert rather than failing the build.
        }
    }

    /**
     * The handler in the decoration chain that owns the `renderCallbacks` — the handler itself, or the
     * real Foundation handler a decorator (Collision's console adapter, Flare, …) wraps as an
     * {@see ExceptionHandler} property. Null when no handler in reach exposes them.
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
            // Per-property, not per-walk: reading an UNINITIALIZED typed property throws, and a single
            // such property on any handler in the decoration chain would otherwise abort ALL
            // render-callback discovery. Skip the unreadable property and keep looking.
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

        // A method-backed closure (`Closure::fromCallable` on an invokable/`[$obj, 'method']`/first-class
        // callable): analyse the real method, since its declaration line is not a closure literal. A bound
        // free function has no owning class to analyse by name — skip it rather than mis-locate it.
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
