<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;

/**
 * Takes its polymorphic relation from a trait. Only ever reflected — never queried.
 *
 * @property int $id
 * @property int $owner_id
 * @property string $owner_type
 */
final class Upload extends Model
{
    use HasOwner;
}
