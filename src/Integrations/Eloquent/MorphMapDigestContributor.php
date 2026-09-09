<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Contributes the polymorphic morph map (`Relation::morphMap()`) to the environment digest (design
 * §10, A4): the alias → FQCN table drives MorphTo discriminator mappings, so a change to it can alter
 * any operation documenting a morph relation. Gated with the Eloquent integration — a document that
 * disables Eloquent never keys its warm fragments on the morph map.
 *
 * Two segments, because the map is read two ways. Alias → model is a keyed lookup, so that half is
 * hashed as a SET, sorted by alias: a reorder no discriminator can see must not churn every warm
 * fragment. Model → alias is not — it is the first alias for the model in iteration order, which is
 * what a discriminator PUBLISHES, so a model carrying two aliases resolves to whichever was registered
 * first. Sorting that away would hash two applications alike and let a warm fragment publish the alias
 * the other one meant, so the resolved answer is hashed beside the set.
 */
final class MorphMapDigestContributor implements EnvironmentDigestContributor
{
    public function digest(): string
    {
        $morphMap = Relation::morphMap();

        // First alias per model, taken in registration order: the same answer `array_search()` gives
        // {@see MorphToSchema}, which is what the discriminator mapping is minted from.
        $resolved = [];
        foreach ($morphMap as $alias => $fqcn) {
            $resolved[$fqcn] ??= (string) $alias;
        }
        ksort($resolved);
        ksort($morphMap);

        $parts = ['morph'];
        foreach ($morphMap as $alias => $fqcn) {
            $parts[] = (string) $alias;
            $parts[] = $fqcn;
        }

        $parts[] = 'aliases';
        foreach ($resolved as $fqcn => $alias) {
            $parts[] = $fqcn;
            $parts[] = $alias;
        }

        return implode("\0", $parts);
    }
}
