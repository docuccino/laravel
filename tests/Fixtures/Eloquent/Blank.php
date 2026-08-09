<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * An undocumented model: no `@property` docblock, no `$casts`/`$dates`/`$fillable` — so no source
 * yields a column. It documents as a bare object AND raises the `eloquent.no-columns` info diagnostic
 * telling the author to add `@property` tags. Only ever reflected.
 */
final class Blank extends Model
{
    /** No timestamp columns either, so the model is genuinely attribute-less. */
    public $timestamps = false;
}
