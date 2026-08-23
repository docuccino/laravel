<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The subject of the include/sparse-fieldset list route: one relation documented by an allow-list
 * comment, one by its own docblock, and columns the stub engine describes — so the golden locks both
 * enums together with where each value's prose came from. Never queried.
 *
 * @property int $id
 * @property string $reference
 * @property string $opened_at
 */
final class Ledger extends Model
{
    protected $guarded = [];

    /** Every form filed against this ledger, oldest first. */
    public function entries(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    /** The clerk who signed the ledger off. */
    public function auditor(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
