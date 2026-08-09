<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A model fixture exercising the framework-injected columns: `HasUuids` (a string uuid primary key),
 * `SoftDeletes` (a nullable `deleted_at`), and the default timestamps (`created_at`/`updated_at`).
 * Documented the idiomatic way with `@property` tags; only ever reflected — never queried.
 *
 * @property string $id The uuid primary key.
 * @property string $label A display label.
 */
final class Vault extends Model
{
    use HasUuids;
    use SoftDeletes;
}
