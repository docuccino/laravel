<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose route key is decided when the request runs — read from configuration — so nothing static
 * can name the column a segment bound to it is matched on. Never queried.
 *
 * @property int $id
 * @property string $reference
 */
final class Dossier extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        $key = config('dossiers.route_key');

        return is_string($key) ? $key : 'reference';
    }
}
