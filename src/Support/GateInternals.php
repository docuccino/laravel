<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use ReflectionClass;
use ReflectionMethod;
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
     * say — so a Gate missing one is unreadable too.
     */
    private const array METHODS = ['getPolicyFromAttribute', 'guessPolicyName'];

    /**
     * @param  array<array-key, mixed>  $policies  the registered class → policy map, as the Gate holds it
     */
    private function __construct(
        private readonly Gate $gate,
        public readonly array $policies,
        public readonly int $beforeHooks,
        public readonly int $afterHooks,
        public readonly bool $guesser,
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

            $policies = $reflection->getProperty('policies')->getValue($gate);
            $before = $reflection->getProperty('beforeCallbacks')->getValue($gate);
            $after = $reflection->getProperty('afterCallbacks')->getValue($gate);

            // Half-read is the one answer neither caller can use: a property that is there but holds
            // something else is a Gate this does not recognise, not one with no hooks.
            if (! is_array($policies) || ! is_array($before) || ! is_array($after)) {
                return null;
            }

            return new self(
                gate: $gate,
                policies: $policies,
                beforeHooks: count($before),
                afterHooks: count($after),
                guesser: $reflection->getProperty('guessPolicyNamesUsingCallback')->getValue($gate) !== null,
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
     * The policy class a `can:` gate on `$model` authorizes with, in the order `getPolicyFor()` resolves
     * it — an exact registration, the model's `#[UsePolicy]`, the name guesser, then a registration on a
     * parent class. Null where nothing answers, or where the answer names a class that is not there.
     */
    public function policyClassFor(string $model): ?string
    {
        try {
            $policy = $this->policies[$model] ?? null;

            if (! is_string($policy)) {
                $policy = $this->call('getPolicyFromAttribute', $model);
            }

            if (! is_string($policy)) {
                foreach ($this->guessedNames($model) as $guess) {
                    if (class_exists($guess)) {
                        $policy = $guess;
                        break;
                    }
                }
            }

            if (! is_string($policy)) {
                foreach ($this->policies as $expected => $registered) {
                    if (is_string($expected) && is_string($registered) && is_subclass_of($model, $expected)) {
                        $policy = $registered;
                        break;
                    }
                }
            }

            return is_string($policy) && class_exists($policy) ? ltrim($policy, '\\') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a registered subject can be reached by the LAST branch of {@see policyClassFor()} — the
     * walk over the map that takes the first registration the model is a subclass of. That branch is
     * the only one whose answer depends on the map's ORDER: an exact registration is a keyed lookup,
     * and the `#[UsePolicy]` and guesser branches read the model rather than the map. So it is the only
     * branch whose registrations owe their sequence to anything keying a cache on the resolution
     * ({@see GatePoliciesDigestContributor}), and the reason the rest can still be keyed as a set.
     *
     * `is_subclass_of()` is false for a class against itself, so a `final` class can never be reached
     * here — and neither can a name that is no class or interface at all, a trait included, since
     * nothing is a subclass of one. Anything this cannot decide answers yes: over-keying costs a
     * rebuild, under-keying replays a resolution that is no longer true.
     */
    public static function shadowable(int|string $subject): bool
    {
        if (! is_string($subject)) {
            return true;
        }

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

    private function call(string $method, string $model): mixed
    {
        $reflected = new ReflectionMethod($this->gate, $method);

        return $reflected->invoke($this->gate, $model);
    }
}
