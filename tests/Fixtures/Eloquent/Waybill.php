<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A model whose route key is a ULID string (`HasUlids`). Only ever reflected — never queried.
 *
 * @property string $id The ulid primary key.
 */
final class Waybill extends Model
{
    use HasUlids;
}
