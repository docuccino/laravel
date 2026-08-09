<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Contributes the polymorphic morph map (`Relation::morphMap()`) to the environment digest (design
 * §10, A4): the alias → FQCN table drives MorphTo discriminator mappings, so a change to it can alter
 * any operation documenting a morph relation. Gated with the Eloquent integration — a document that
 * disables Eloquent never keys its warm fragments on the morph map. Deterministic: the map is sorted
 * by alias before hashing.
 */
final class MorphMapDigestContributor implements EnvironmentDigestContributor
{
    public function digest(): string
    {
        $morphMap = Relation::morphMap();
        ksort($morphMap);

        $records = [];
        foreach ($morphMap as $alias => $fqcn) {
            $records[] = $alias.'=>'.$fqcn;
        }

        return 'morph:'.implode(',', $records);
    }
}
