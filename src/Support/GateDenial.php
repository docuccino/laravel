<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Closure;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Whether a route's `can:` gate is one no request can fail — the question behind the implicit 403.
 * That 403 is synthesized from the PRESENCE of a gate, so a policy method whose whole body is
 * `return true;` leaves the document promising an error the endpoint cannot produce, which reaches a
 * consumer as a dead `catch` branch in a generated client.
 *
 * Deliberately narrow, and every uncertainty answers "it can deny" ({@see GateBody} owns what counts).
 * Narrow in a second way too: the report only fires where the reader could act on it, which means the
 * ability method has to be DECLARED in the application's own code. A policy shipped by a package, or a
 * vendor base class an application policy inherits from, is a body nobody reading the diagnostic can
 * tighten — so it is silent there rather than naming an edit in someone else's repository.
 *
 * Three shapes decide the gate somewhere the method's body cannot be seen, and each is checked rather
 * than assumed away: a `Gate::before`/`Gate::after` registration or a policy `before()` method; a route
 * a guest can reach whose policy method refuses guests, which Laravel denies without ever calling the
 * method ({@see methodAllowsGuests()}); and a gate whose argument is not a class a build can name,
 * which resolves to a `Gate::define`d closure instead of to any policy.
 *
 * Resolution goes through {@see GateInternals} — Laravel's own order, without ever building a policy.
 * The cost of not building one is a container rebinding: an application that binds a policy class to a
 * different concrete runs that concrete, while this reads the source of the class the Gate names. The
 * blast radius is one Info diagnostic, because the 403 publishes either way.
 * What no route file reflects keys the fragment cache instead ({@see GatePoliciesDigestContributor}).
 */
final class GateDenial
{
    /**
     * @param  Closure(): ?Gate  $gate  resolved when a gate is actually read, not when this is built: an
     *                                  application that replaced the default auth providers has no Gate to
     *                                  bind, and that is a check which says nothing rather than a failed build
     * @param  Closure(string): bool  $isVendorFile  the app's vendor boundary. Required: the narrowness the
     *                                               class docblock states IS the contract, and a construction
     *                                               that left it out would quietly get a weaker check that
     *                                               names a body in somebody else's package
     */
    public function __construct(
        private readonly Closure $gate,
        private readonly Closure $isVendorFile,
    ) {}

    /**
     * The policy method this gate provably cannot be denied by — `App\Policies\WidgetPolicy::view`,
     * named for the class that DECLARES the method rather than the one the gate resolved to, so the
     * reader opens a file the body is really in — or null whenever the gate can deny OR nothing here
     * could settle it. The two are one answer on purpose: both mean the 403 keeps its place and nothing
     * is reported.
     */
    public function undeniablePolicyMethod(RouteContext $context, CanGate $gate): ?string
    {
        $internals = GateInternals::read(($this->gate)());
        // A Gate this cannot read counts as one that HAS hooks — the conservative answer, not the
        // convenient one.
        if ($internals === null || $internals->hooksRegistered()) {
            return null;
        }

        $model = $this->model($context, $gate);
        if ($model === null) {
            return null;
        }

        // A `#[UsePolicy]` attribute decides part of the resolution, and the model's own file is only
        // where the FIRST of the two attribute branches looks: Laravel 13 walks the parents too
        // ({@see GateInternals::resolutionBranches()}), so adding the attribute to a base model changes
        // which policy the gate resolves to. Recorded on every version rather than behind the branch,
        // because the hierarchy is the honest answer to where the fact can be WRITTEN and over-keying
        // only costs a rebuild.
        $context->recordDependencyFiles(DeclarationFiles::of($model));

        // Whatever the resolution answers, and not only where it answered nothing: the guesser is asked
        // BEFORE the fallback to a parent class's registration, so a policy can be found with the
        // conventional name still absent — and writing that file would change which policy the gate
        // resolves to while the fragment is warm.
        $this->recordAbsentPolicies($context, $internals->guessedNames($model));

        $policy = $internals->policyClassFor($model);
        if ($policy === null) {
            return null;
        }

        // Both files, because they can differ and either can change the answer: `before()` would be added
        // to the policy class, while an inherited or trait-provided ability method is written elsewhere.
        $this->recordClassFile($context, $policy);

        $name = str_contains($gate->ability, '-') ? Str::camel($gate->ability) : $gate->ability;
        if (! method_exists($policy, $name)) {
            // Laravel's own ability→method rule, and a method the policy does not declare falls through
            // to a `Gate::define`d closure — not a body this reads.
            return null;
        }

        $method = new ReflectionMethod($policy, $name);
        $file = $method->getFileName();
        if ($file === false) {
            return null;
        }
        $context->recordDependencyFiles([$file]);

        // Where the body is WRITTEN is what decides whether the reader can act: an inherited or
        // trait-provided ability method belongs to whoever ships that file, and the diagnostic's remedy
        // is an edit to it.
        if (($this->isVendorFile)($file)) {
            return null;
        }

        if (method_exists($policy, 'before')) {
            return null;
        }

        // A guest never reaches a gate behind auth middleware. Where one can, Laravel skips a policy
        // method that refuses guests and denies, so `return true;` is not the whole story.
        if (! AuthMiddlewareDetector::matches($context) && ! $this->methodAllowsGuests($method)) {
            return null;
        }

        // A body nobody could read is one that can deny — the false negative is a 403 that stays
        // published, the false positive is an author invited to hide a real error.
        return GateBody::read($context, $method, $file) === GateBody::AlwaysAllows
            ? $method->getDeclaringClass()->getName().'::'.$name
            : null;
    }

    /**
     * The class the gate authorizes against, or null where the argument is not one a build can name: no
     * argument at all (an ability-only gate), a quoted literal, or a route parameter nothing binds a
     * model to.
     */
    private function model(RouteContext $context, CanGate $gate): ?string
    {
        $argument = $gate->arguments[0] ?? null;
        if ($argument === null) {
            return null;
        }

        if (CanGate::isClassName($argument)) {
            return ltrim($argument, '\\');
        }

        return $context->routeBindings[$argument] ?? null;
    }

    /**
     * The convention resolves by `class_exists`, so a policy the application has not written yet is a
     * file this build READ the absence of. Recording where it would go makes creating it invalidate the
     * fragment, instead of leaving a warm build repeating the verdict from before it existed. Recorded
     * even where the file is already there: over-keying costs a rebuild, under-keying replays a verdict
     * that is no longer true.
     *
     * @param  list<string>  $names
     */
    private function recordAbsentPolicies(RouteContext $context, array $names): void
    {
        foreach ($names as $name) {
            $context->recordDependencyFiles(Psr4ClassFile::candidates($name));
        }
    }

    private function recordClassFile(RouteContext $context, string $class): void
    {
        if (! class_exists($class)) {
            return;
        }

        $file = (new ReflectionClass($class))->getFileName();
        if ($file !== false) {
            $context->recordDependencyFiles([$file]);
        }
    }

    /**
     * Laravel's `methodAllowsGuests`: the first parameter must exist and admit null. A policy method
     * with NO parameters refuses guests, which is why "the method ignores $user" is never the test.
     */
    private function methodAllowsGuests(ReflectionMethod $method): bool
    {
        $parameters = $method->getParameters();

        return isset($parameters[0]) && $this->parameterAllowsGuests($parameters[0]);
    }

    private function parameterAllowsGuests(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return true;
        }

        try {
            return $parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === null;
        } catch (Throwable) {
            return false;
        }
    }
}
