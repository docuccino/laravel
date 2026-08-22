<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A uuid-keyed workbench model that exists to be the target of Gadget's `beacon()` relation, so a
 * `beacon_id` filter provably types off THIS model's key. Never queried — only ever reflected.
 *
 * @property string $id The uuid primary key.
 */
final class Beacon extends Model
{
    use HasUuids;
}
