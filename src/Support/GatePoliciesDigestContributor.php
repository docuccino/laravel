<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Closure;
use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Docuccino\Laravel\Integrations\Support\AuthConfigDigestContributor;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Feeds the booted app's gate registrations — the class → policy map, whether a policy-name guesser is
 * installed, and how many `Gate::before`/`Gate::after` hooks there are — into the environment digest
 * (design §10). {@see GateDenial} reads all three, and none of them is reflected by any file a route
 * records: they are written in a service provider, so registering a policy or adding a `before` hook
 * afterwards would leave every warm fragment saying what the old registrations implied.
 *
 * The map's KEYS and VALUES both count, since either changes which policy a `can:` gate resolves to;
 * the hook counts are enough, because a hook's body is never read — its mere presence is what silences
 * the check. A Gate this cannot read ({@see GateInternals}) contributes the empty string: a made-up
 * segment would key the cache on a fact nothing here established.
 *
 * Two segments carry the map, because resolution reads it two ways: most of it is a keyed lookup and is
 * hashed as a sorted SET, while the registrations the subclass-fallback branch can reach owe their
 * SEQUENCE too — {@see GateInternals::shadowable()} owns that rule and states why.
 *
 * Registered unconditionally: gates are the framework's own authorization vocabulary and belong to no
 * package, the reason {@see AuthConfigDigestContributor} is too.
 */
final class GatePoliciesDigestContributor implements EnvironmentDigestContributor
{
    /**
     * @param  Closure(): ?Gate  $gate  resolved when the digest is taken rather than when the extension set
     *                                  is built, so an app with no Gate bound contributes nothing instead of
     *                                  failing to construct
     */
    public function __construct(private readonly Closure $gate) {}

    public function digest(): string
    {
        $internals = GateInternals::read(($this->gate)());
        if ($internals === null) {
            return '';
        }

        $records = [];
        $shadowable = [];
        foreach ($internals->policies as $class => $policy) {
            $resolved = is_string($policy) ? $policy : get_debug_type($policy);
            $records[$class] = $resolved;
            if (GateInternals::shadowable($class)) {
                $shadowable[$class] = $resolved;
            }
        }
        ksort($records);

        $parts = ['gate-policies'];
        foreach ($records as $class => $resolved) {
            $parts[] = (string) $class;
            $parts[] = $resolved;
        }

        // Registration order, deliberately unsorted — {@see GateInternals::shadowable()} says why.
        $parts[] = 'subclass-order';
        foreach ($shadowable as $class => $resolved) {
            $parts[] = (string) $class;
            $parts[] = $resolved;
        }

        return implode("\0", [
            ...$parts,
            'guesser',
            $internals->guesser ? 'y' : 'n',
            'before',
            (string) $internals->beforeHooks,
            'after',
            (string) $internals->afterHooks,
        ]);
    }
}
