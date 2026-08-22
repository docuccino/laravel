<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A `HasUuids` model that ALSO carries a stale plain-string cast on its key — the trait's uuid format
 * must win over the cast. Only ever reflected — never queried.
 *
 * @property string $id The uuid primary key.
 */
final class Locker extends Model
{
    use HasUuids;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'string',
    ];
}
