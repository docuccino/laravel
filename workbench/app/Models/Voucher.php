<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workbench\App\Models\Concerns\HashesItsKey;

/**
 * A model that resolves its own bindings through a trait, so neither its key nor a column a route names
 * is what a segment bound to it is matched on. Never queried.
 *
 * @property int $id
 * @property string $code
 */
final class Voucher extends Model
{
    use HashesItsKey;

    protected $guarded = [];

    /** The forms redeemed against this voucher. */
    public function entries(): HasMany
    {
        return $this->hasMany(Form::class);
    }
}
