<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use Throwable;

/**
 * The gate registrations behind a `can:` middleware, read off the concrete Illuminate {@see Gate} by
 * reflection because the contract publishes no accessor for any of them: the policy map, the two hook
 * lists, and the naming machinery that says which policy class a model resolves to.
 *
 * No policy OBJECT is ever constructed here, and that is the whole of the claim. Both readers want a
 * class NAME and a method's source, so `Gate::getPolicyFor()` — which ends in
 * `$container->make($policy)` — is not the way to ask: that runs the policy's constructor, resolves
 * whatever it injects and fires every `Container::resolving` hook, at documentation-build time.
 *
 * Application code still RUNS, and the claim is deliberately not wider than it is. Resolution
 * autoloads — `class_exists()` on a guessed name, `is_subclass_of()` on the model, Laravel's own
 * `getPolicyFromAttribute()` — and a file's top-level statements run when it is loaded, exactly as they
 * do for every controller, model and form request a build already loads. A registered
 * `guessPolicyNamesUsing()` callback runs too, because it IS the naming answer and cannot be read any
 * other way.
 *
 * How many resolution branches there ARE is a fact about the framework the application resolved, not
 * about the one this was written against: 12 walks four, 13 walks five. {@see resolutionBranches()}
 * owns that and says how the installed grammar is read.
 *
 * {@see read()} answering null means someone else's Gate. Each caller states its own degradation
 * beside its own use, because they differ: {@see GateDenial} treats it as a Gate that HAS hooks and
 * stays silent, while {@see GatePoliciesDigestContributor} contributes nothing.
 */
final class GateInternals
{
    /** Every property read below, so a Gate missing any of them is unreadable rather than half-read. */
    private const array PROPERTIES = ['policies', 'beforeCallbacks', 'afterCallbacks', 'guessPolicyNamesUsingCallback'];

    /**
     * Likewise the two resolution steps. Skipping either silently would answer with a DIFFERENT policy
     * than the one an authorize call reaches — a `#[UsePolicy]` attribute overruled by the convention,
     * say — so a Gate missing one is unreadable too. Present is all this asks; what
     * `getPolicyFromAttribute()` can be ASKED is a second question, and
     * {@see readsParentAttributes()} reads its signature for that.
     */
    private const array METHODS = ['getPolicyFromAttribute', 'guessPolicyName'];

    /**
     * @param  array<string, mixed>  $policies  the registered class → policy map, keyed as {@see read()}
     *                                          normalised it
     * @param  bool  $parentAttributes  whether the installed attribute step will walk a model's parents —
     *                                  {@see readsParentAttributes()} for how it is decided and why not
     *                                  from a version
     */
    private function __construct(
        private readonly Gate $gate,
        public readonly array $policies,
        public readonly int $beforeHooks,
        public readonly int $afterHooks,
        public readonly bool $guesser,
        public readonly bool $parentAttributes,
    ) {}

    /** Null for a Gate whose internals this does not recognise, including no Gate at all. */
    public static function read(?Gate $gate): ?self
    {
        if ($gate === null) {
            return null;
        }

        try {
            $reflection = new ReflectionObject($gate);

            foreach (self::PROPERTIES as $property) {
                if (! $reflection->hasProperty($property)) {
                    return null;
                }
            }

            foreach (self::METHODS as $method) {
                if (! $reflection->hasMethod($method)) {
                    return null;
                }
            }

            $rawPolicies = $reflection->getProperty('policies')->getValue($gate);
            $before = $reflection->getProperty('beforeCallbacks')->getValue($gate);
            $after = $reflection->getProperty('afterCallbacks')->getValue($gate);

            // Half-read is the one answer neither caller can use: a property that is there but holds
            // something else is a Gate this does not recognise, not one with no hooks.
            if (! is_array($rawPolicies) || ! is_array($before) || ! is_array($after)) {
                return null;
            }

            // A registration key is a class name, so it is a string wherever it came from — but the
            // property is somebody else's and reflection hands its keys over as `array-key`. Narrowed
            // here, once, because a reader that took that union would not be told off for handing it to
            // a `string` parameter: PHPStan treats a loose array's key as a benevolent union and lets
            // the call through, so the type has to be made true at the read rather than trusted at the
            // signature.
            $policies = [];
            foreach ($rawPolicies as $class => $policy) {
                $policies[(string) $class] = $policy;
            }

            return new self(
                gate: $gate,
                policies: $policies,
                beforeHooks: count($before),
                afterHooks: count($after),
                guesser: $reflection->getProperty('guessPolicyNamesUsingCallback')->getValue($gate) !== null,
                parentAttributes: self::readsParentAttributes($reflection->getMethod('getPolicyFromAttribute')),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether anything is registered that can answer an ability over a policy's head. */
    public function hooksRegistered(): bool
    {
        return $this->beforeHooks > 0 || $this->afterHooks > 0;
    }

    /**
     * The policy class a `can:` gate on `$model` authorizes with, taking the branches
     * {@see resolutionBranches()} lists in the order `getPolicyFor()` walks them. Null where nothing
     * answers, or where the answer names a class that is not there.
     *
     * The FIRST branch to name anything wins, and existence is checked once at the end rather than per
     * branch — the framework resolves whatever a branch found without asking whether it is loadable, so
     * a registration naming a missing class is a gate that answers nothing, not one that falls through
     * to the next branch.
     */
    public function policyClassFor(string $model): ?string
    {
        try {
            $policy = null;
            foreach ($this->resolutionBranches() as $branch) {
                $policy = $branch($model);
                if (is_string($policy)) {
                    break;
                }
            }

            return is_string($policy) && class_exists($policy) ? ltrim($policy, '\\') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Every step `getPolicyFor()` resolves through, in its order and as a countable list — an exact
     * registration, the model's own `#[UsePolicy]`, the name guesser, a registration on a parent type,
     * and on Laravel 13 the attribute step again over the model's PARENTS.
     *
     * The list is the mirror's statement of its own grammar rather than prose about it, so the
     * framework's own count can be held against it and a sixth branch fails loudly instead of
     * degrading to silence for the models it would answer for.
     *
     * @return list<Closure(string): mixed>
     */
    private function resolutionBranches(): array
    {
        $branches = [
            fn (string $model): mixed => $this->policies[$model] ?? null,
            fn (string $model): mixed => $this->call('getPolicyFromAttribute', $model),
            fn (string $model): mixed => $this->firstGuessedPolicy($model),
            fn (string $model): mixed => $this->registeredForParentOf($model),
        ];

        // Asking an older framework for parents would be asking for an argument its method has no
        // parameter for. Leaving the branch off is not a degradation there: 12 does not resolve a
        // parent's attribute either, so the mirror and the framework agree on null.
        if ($this->parentAttributes) {
            $branches[] = fn (string $model): mixed => $this->call('getPolicyFromAttribute', $model, true);
        }

        return $branches;
    }

    /**
     * Whether the installed attribute step will walk a model's parents, read off the signature the
     * framework actually shipped: 13's `getPolicyFromAttribute()` takes an `includeParents` flag and
     * 12's takes none.
     *
     * A signature and not a version, because the class that answers is whichever one the container
     * bound — it may come from `laravel/framework` or from a bare `illuminate/auth`, and either can be
     * subclassed — so a lockfile lookup would be answering about a package name this never asked for.
     * `method_exists()` is no use either: both majors publish the method, and the analyser folds the
     * call on a known class to constant true. The parameter's NAME is part of the check because the
     * branch passes an argument to it, and a second parameter meaning something else is a grammar this
     * cannot speak.
     */
    private static function readsParentAttributes(ReflectionMethod $method): bool
    {
        $parameters = $method->getParameters();
        if (! isset($parameters[1]) || $parameters[1]->getName() !== 'includeParents') {
            return false;
        }

        $type = $parameters[1]->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === 'bool';
    }

    /** The first conventional name a class exists under, which is how the framework reads the guesses. */
    private function firstGuessedPolicy(string $model): ?string
    {
        foreach ($this->guessedNames($model) as $guess) {
            if (class_exists($guess)) {
                return $guess;
            }
        }

        return null;
    }

    /** The first registration `$model` is a subclass of — the one branch that reads the map in order. */
    private function registeredForParentOf(string $model): ?string
    {
        foreach ($this->policies as $expected => $registered) {
            if (is_string($registered) && is_subclass_of($model, $expected)) {
                return $registered;
            }
        }

        return null;
    }

    /**
     * Whether a registered subject can be reached by the subclass-fallback branch of
     * {@see policyClassFor()} — the walk over the map that takes the first registration the model is a
     * subclass of. That branch is the only one whose answer depends on the map's ORDER: an exact
     * registration is a keyed lookup, and the guesser and both `#[UsePolicy]` branches read the model
     * rather than the map. So it is the only branch whose registrations owe their sequence to anything
     * keying a cache on the resolution ({@see GatePoliciesDigestContributor}), and the reason the rest
     * can still be keyed as a set.
     *
     * `is_subclass_of()` is false for a class against itself, so a `final` class can never be reached
     * here — and neither can a name that is no class or interface at all, a trait included, since
     * nothing is a subclass of one. Anything this cannot decide answers yes: over-keying costs a
     * rebuild, under-keying replays a resolution that is no longer true.
     */
    public static function shadowable(string $subject): bool
    {
        try {
            if (interface_exists($subject)) {
                return true;
            }

            return class_exists($subject) && ! (new ReflectionClass($subject))->isFinal();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The names the Gate would look for a policy under, whether or not any of them exists — what makes
     * the answer above depend on a class the application has not written yet.
     *
     * @return list<string>
     */
    public function guessedNames(string $model): array
    {
        try {
            $guessed = $this->call('guessPolicyName', $model);
        } catch (Throwable) {
            return [];
        }

        $names = [];
        foreach (is_array($guessed) ? $guessed : [$guessed] as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = ltrim($name, '\\');
            }
        }

        return $names;
    }

    private function call(string $method, string $model, bool ...$flags): mixed
    {
        $reflected = new ReflectionMethod($this->gate, $method);

        return $reflected->invoke($this->gate, $model, ...$flags);
    }
}
