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
 * Two segments carry the map, because resolution reads it two ways. Most of it is a SET: an exact
 * registration is a keyed lookup, so sorting keeps a reorder from churning every warm fragment over a
 * change no resolution can see. But the last resolution branch walks the map and takes the FIRST
 * registration the model is a subclass of, so the registrations that branch can reach owe their
 * SEQUENCE as well ({@see GateInternals::shadowable()}) — sorting those away is the cache being told
 * nothing changed while the policy a gate resolves to did.
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
            $record = (string) $class.'=>'.(is_string($policy) ? $policy : get_debug_type($policy));
            $records[] = $record;
            if (GateInternals::shadowable($class)) {
                $shadowable[] = $record;
            }
        }
        sort($records);

        return 'gate-policies:'.implode(',', $records)
            .'|subclass-order:'.implode(',', $shadowable)
            .'|guesser:'.($internals->guesser ? 'y' : 'n')
            .'|before:'.$internals->beforeHooks
            .'|after:'.$internals->afterHooks;
    }
}
