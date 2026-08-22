<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model fixture whose refusals carry LITERAL foreign keys: a conditional relation (two `belongsTo`
 * calls in one body) and a target that loads but isn't a model. Each vetoes exactly the column it
 * names — the clean `vault_id` match beside them still types. Only ever reflected — never queried.
 *
 * @property bool $use_branch Picks the conditional relation's arm at runtime.
 */
final class ContestedRelationModel extends Model
{
    /**
     * Readable, and contested by the conditional relation's first arm — `contested_id` must refuse.
     *
     * @return BelongsTo<Vault, $this>
     */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(Vault::class, 'contested_id');
    }

    /**
     * A clean match the literal-key refusals beside it must NOT suppress.
     *
     * @return BelongsTo<Vault, $this>
     */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /**
     * Which arm runs is a runtime fact — both calls are refusals, each naming its literal key.
     *
     * @return BelongsTo<Vault, $this>|BelongsTo<FilterCastModel, $this>
     */
    public function conditional(): BelongsTo
    {
        if ($this->use_branch) {
            return $this->belongsTo(Vault::class, 'contested_id');
        }

        return $this->belongsTo(FilterCastModel::class, 'branch_id');
    }

    /**
     * A target that exists but is not a model — refused, its file still keying the cache.
     */
    public function relic(): BelongsTo
    {
        return $this->belongsTo(CustomCaster::class, 'relic_id');
    }
}
