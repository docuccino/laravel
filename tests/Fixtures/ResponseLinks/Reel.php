<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that writes its own serialised form, sending its uuid under `id`. Never queried.
 *
 * @property int $id
 * @property string $uuid
 */
final class Reel extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->uuid];
    }
}
