<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that binds on a column of its own choosing, written the idiomatic way — as a
 * `getRouteKeyName()` override. The column lives in a method body, so nothing static can read it: the
 * build must neither name it nor type the segment off the primary key it is demonstrably not. Never
 * queried.
 *
 * @property string $slug
 */
final class Article extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
