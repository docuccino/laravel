<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Contributes the morph map to the environment digest: a `morphTo`'s type column publishes the values
 * {@see MorphTypeValues} reads out of it, so a changed map must not replay a fragment built under the
 * old one. Gated with the Eloquent integration.
 *
 * What is hashed is what the reader consumes — whether the map is enforced, and each model's resolved
 * alias. That alias is the FIRST one registered for the model (`array_search()`), so it is taken in
 * registration order before the pairs are sorted by class: sorting first would hash two maps alike
 * that publish different values.
 */
final class MorphMapDigestContributor implements EnvironmentDigestContributor
{
    public function digest(): string
    {
        $resolved = [];
        foreach (Relation::morphMap() as $alias => $class) {
            $resolved[$class] ??= (string) $alias;
        }
        ksort($resolved, SORT_STRING);

        $parts = ['enforced', Relation::requiresMorphMap() ? '1' : '0', 'aliases'];
        foreach ($resolved as $class => $alias) {
            $parts[] = $class;
            $parts[] = $alias;
        }

        return implode("\0", $parts);
    }
}
