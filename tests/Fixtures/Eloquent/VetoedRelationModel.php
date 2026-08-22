<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model fixture carrying a WILDCARD refusal: relations whose foreign key can't be read at all (a
 * constant, an unpack) could serve any column, so even the readable `vault_id` match must refuse.
 * Only ever reflected — never queried.
 */
final class VetoedRelationModel extends Model
{
    private const DYNAMIC_KEY = 'dynamic_id';

    private const LINK = [Vault::class, 'imported_id'];

    /**
     * Readable on its own — but the wildcards below suppress every hop answer on this model.
     *
     * @return BelongsTo<Vault, $this>
     */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /**
     * A non-literal foreign-key argument — the related class still reads, the key is a wildcard.
     *
     * @return BelongsTo<Vault, $this>
     */
    public function dynamic(): BelongsTo
    {
        return $this->belongsTo(Vault::class, self::DYNAMIC_KEY);
    }

    /**
     * Unpacked arguments — nothing about the call is positionally knowable.
     *
     * @return BelongsTo<Vault, $this>
     */
    public function imported(): BelongsTo
    {
        return $this->belongsTo(...self::LINK);
    }
}
