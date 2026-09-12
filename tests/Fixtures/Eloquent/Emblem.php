<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A read-only model whose only serialised key is an appended accessor: no `@property` tags, no
 * `$casts`/`$dates`/`$fillable` and no timestamps, so no source yields a COLUMN — but `$appends` puts
 * `badge` in every response, so the schema is not a bare object and `eloquent.no-columns` must stay
 * quiet. Only ever reflected.
 */
final class Emblem extends Model
{
    /** No timestamp columns, so nothing but the append reaches the schema. */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $appends = ['badge'];

    public function getBadgeAttribute(): string
    {
        return 'gold';
    }
}
