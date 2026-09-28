<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionParameter;
use Throwable;

/**
 * Reflects the booted app's exception handler for the callbacks it registered via
 * `$exceptions->render(…)` (`Illuminate\Foundation\Exceptions\Handler::$renderCallbacks`) and the one
 * `$exceptions->respond(…)` stores (`$finalizeResponseCallback`, which sees every rendered response),
 * catching provider- and package-registered handlers a static AST scan would miss (design §6). Memoised: the
 * handler is reflected once per build, and each callback's source location + first-parameter type feed
 * the engine. The exception map (`$exceptions->map(…)`, `$exceptionMap`) is read here too, since it decides
 * which exception every one of those callbacks is asked about.
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

    private ?RespondCallback $respond = null;

    /** The label of a respond callback that is registered but could not be located for analysis. */
    private ?string $respondUnlocated = null;

    /** @var list<ExceptionMapping> */
    private array $mappings = [];

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

    /** The `respond()` callback, where one is registered and could be located. */
    public function respondCallback(): ?RespondCallback
    {
        $this->discover();

        return $this->respond;
    }

    /**
     * The label of a `respond()` callback that is registered but could not be located — a bound free
     * function, a callable with no source. Null where there is none, or it was located.
     */
    public function respondUnlocated(): ?string
    {
        $this->discover();

        return $this->respondUnlocated;
    }

    /**
     * The exception map in registration order, which is `mapException()`'s match order: the first entry whose
     * key the THROWN exception `is_a` translates it, and nothing translates the translation.
     *
     * @return list<ExceptionMapping>
     */
    public function exceptionMappings(): array
    {
        $this->discover();

        return $this->mappings;
    }

    /** The entry that translates a throw of `$thrownFqcn`, or null where none does. */
    public function mappingFor(string $thrownFqcn): ?ExceptionMapping
    {
        foreach ($this->exceptionMappings() as $mapping) {
            if ($mapping->matches($thrownFqcn)) {
                return $mapping;
            }
        }

        return null;
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

            $this->discoverRespond($handler);
            $this->discoverMappings($handler);
        } catch (Throwable) {
            // Unexpected handler shape: leave the tier inert rather than fail the build.
        }
    }

    /**
     * `respond()` stores its callable as given — not through `Closure::fromCallable()` the way a render
     * callback is — so it is converted here, and located the same way a render callback is.
     */
    private function discoverRespond(ExceptionHandler $handler): void
    {
        $reflection = new ReflectionObject($handler);
        if (! $reflection->hasProperty('finalizeResponseCallback')) {
            return;
        }

        $value = $reflection->getProperty('finalizeResponseCallback')->getValue($handler);
        if ($value === null) {
            return;
        }

        if (! is_callable($value)) {
            $this->respondUnlocated = $this->describe($value);

            return;
        }

        $closure = Closure::fromCallable($value);
        $function = new ReflectionFunction($closure);
        $at = LocatedCallable::of($function);
        if ($at === null) {
            $this->respondUnlocated = $this->describe($closure);

            return;
        }

        $names = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $function->getParameters());

        $this->respond = new RespondCallback($at, $names[0] ?? null, $names[1] ?? null, $names[2] ?? null);
    }

    /**
     * `map()` stores every entry as a Closure keyed by the class it matches. A class-string target arrives
     * wrapped in a closure `map()` itself writes, holding the class as `$to` — recognised by where it is
     * written, and read as the class it names, since its body builds `new $to(…)` from a variable no fold
     * can name. Anything else is the application's own mapper, located as a render callback is.
     */
    private function discoverMappings(ExceptionHandler $handler): void
    {
        $reflection = new ReflectionObject($handler);
        if (! $reflection->hasProperty('exceptionMap')) {
            return;
        }

        $value = $reflection->getProperty('exceptionMap')->getValue($handler);
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $from => $mapper) {
            if (! is_string($from) || ! $mapper instanceof Closure) {
                continue;
            }

            $from = ltrim($from, '\\');
            $function = new ReflectionFunction($mapper);

            $target = $this->wrappedTarget($function);
            if ($target !== null) {
                $target = ltrim($target, '\\');
                $this->mappings[] = new ExceptionMapping($from, sprintf('map(%s, %s)', $from, $target), target: $target);

                continue;
            }

            $at = LocatedCallable::of($function);
            $this->mappings[] = new ExceptionMapping(
                $from,
                $this->describe($mapper),
                at: $at,
                parameterName: $at === null ? null : ($function->getParameters()[0] ?? null)?->getName(),
            );
        }
    }

    /** The class a `map(From::class, To::class)` entry names, read off the closure `map()` wraps it in. */
    private function wrappedTarget(ReflectionFunction $function): ?string
    {
        $scope = $function->getClosureScopeClass();
        if ($scope === null || ! $scope->hasMethod('map')) {
            return null;
        }

        $map = $scope->getMethod('map');
        $line = $function->getStartLine();
        if ($function->getFileName() !== $map->getFileName() || $line === false || $line < $map->getStartLine() || $line > $map->getEndLine()) {
            return null;
        }

        $target = $function->getStaticVariables()['to'] ?? null;

        return is_string($target) ? $target : null;
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
        $at = LocatedCallable::of($function);
        if ($parameters === [] || $at === null) {
            return null;
        }

        $type = $parameters[0]->getType();
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return new RenderCallback($at, $parameters[0]->getName(), ltrim($type->getName(), '\\'));
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
