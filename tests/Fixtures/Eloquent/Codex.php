<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A uuid-keyed model whose primary key is RENAMED — a `belongsTo` pointing here defaults its foreign
 * key to `codex_guid`, not `codex_id`. Only ever reflected — never queried.
 *
 * @property string $guid The uuid primary key.
 */
final class Codex extends Model
{
    use HasUuids;

    protected $primaryKey = 'guid';
}
