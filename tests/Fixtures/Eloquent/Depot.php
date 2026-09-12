<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A read-only model whose only serialised key is an eager-loaded relation: no `@property` tags, no
 * `$casts`/`$dates`/`$fillable` and no timestamps, so no source yields a COLUMN — but `$with` puts
 * `keeper` in every response, so the schema is not a bare object. Only ever reflected.
 */
final class Depot extends Model
{
    /** No timestamp columns, so nothing but the eager load reaches the schema. */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $with = ['keeper'];

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function keeper(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
