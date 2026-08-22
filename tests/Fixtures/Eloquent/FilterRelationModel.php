<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A model fixture whose `belongsTo` relations cover every READABLE shape the filter foreign-key hop
 * types: default and explicit foreign keys, named arguments, owner keys, a renamed related primary
 * key, a string-literal class name — plus the shapes that yield nothing at all (a `morphTo`, a
 * first-class callable, non-relation helpers) and two relations contesting one column. Deliberately
 * carries NO partially-readable `belongsTo` (see the veto fixtures for those). Only ever reflected —
 * never queried.
 */
final class FilterRelationModel extends Model
{
    /**
     * Default foreign key (`vault_id`) to a uuid-keyed model.
     *
     * @return BelongsTo<Vault, $this>
     */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /**
     * A camelCase method — the default foreign key snake-cases it (`vault_keeper_id`).
     *
     * @return BelongsTo<Vault, $this>
     */
    public function vaultKeeper(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /**
     * A chained modifier — the one `belongsTo` inside still reads (`waybill_id`, ulid).
     *
     * @return BelongsTo<Waybill, $this>
     */
    public function waybill(): BelongsTo
    {
        return $this->belongsTo(Waybill::class)->withDefault();
    }

    /**
     * An explicit foreign key as the second argument.
     *
     * @return BelongsTo<Vault, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Vault::class, 'custom_owner_id');
    }

    /**
     * An explicit foreign key as a NAMED argument.
     *
     * @return BelongsTo<Waybill, $this>
     */
    public function keeper(): BelongsTo
    {
        return $this->belongsTo(Waybill::class, foreignKey: 'named_keeper_id');
    }

    /**
     * Default foreign key (`sibling_id`) to a default int-keyed model.
     *
     * @return BelongsTo<FilterCastModel, $this>
     */
    public function sibling(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class);
    }

    /**
     * An owner key naming a CAST column on the related model (`quantity`, integer).
     *
     * @return BelongsTo<FilterCastModel, $this>
     */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class, 'reference_key', 'quantity');
    }

    /**
     * An owner key naming an uncast, non-key column — nothing types it.
     *
     * @return BelongsTo<FilterCastModel, $this>
     */
    public function opaque(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class, 'opaque_key', 'untyped_column');
    }

    /**
     * Default foreign key to a model whose primary key is renamed (`codex_guid`, uuid).
     *
     * @return BelongsTo<Codex, $this>
     */
    public function codex(): BelongsTo
    {
        return $this->belongsTo(Codex::class);
    }

    /**
     * An owner key naming a custom-cast column — the caster owns the wire form, so nothing types it.
     *
     * @return BelongsTo<FilterCastModel, $this>
     */
    public function sealed(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class, 'sealed_key', 'custom');
    }

    /**
     * A literal relation-name argument (and explicit `null` defaults) — the default foreign key
     * snake-cases the NAME, not the method (`archive_id`).
     *
     * @return BelongsTo<Vault, $this>
     */
    public function legacyArchive(): BelongsTo
    {
        return $this->belongsTo(Vault::class, null, null, 'archive');
    }

    /**
     * A string-literal class name works exactly as a `::class` fetch does (`ancient_id`, uuid).
     *
     * @return BelongsTo<Vault, $this>
     */
    public function ancient(): BelongsTo
    {
        return $this->belongsTo('Docuccino\\Laravel\\Tests\\Fixtures\\Eloquent\\Vault', 'ancient_id');
    }

    /**
     * An owner key naming an ENUM-cast column on the related model.
     *
     * @return BelongsTo<FilterCastModel, $this>
     */
    public function flagged(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class, 'flagged_key', 'status');
    }

    /**
     * A first-class callable references `belongsTo` without calling it — not a relation.
     */
    public function vaultResolver(): Closure
    {
        return $this->belongsTo(...);
    }

    /**
     * A helper needing an argument is not a zero-argument relation accessor — were the candidate
     * filter to break, its literal `pivot_id` would surface and fail the reader's exact-set pin.
     *
     * @return BelongsTo<Vault, $this>
     */
    public function pivotTo(string $context): BelongsTo
    {
        return $this->belongsTo(Vault::class, 'pivot_id');
    }

    /**
     * A static constructor is not a relation accessor (a static body can never call `$this->belongsTo`,
     * so this exclusion is unobservable from the outside — the method is here for the candidate set).
     */
    public static function archived(): self
    {
        return new self;
    }

    /**
     * A polymorphic relation — its `attachable_id` column references no single model.
     *
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * One of two relations declaring the SAME explicit foreign key (`shared_id`).
     *
     * @return BelongsTo<Vault, $this>
     */
    public function first(): BelongsTo
    {
        return $this->belongsTo(Vault::class, 'shared_id');
    }

    /**
     * The other `shared_id` contestant — the contest has no single truthful answer.
     *
     * @return BelongsTo<FilterCastModel, $this>
     */
    public function second(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class, 'shared_id');
    }
}
