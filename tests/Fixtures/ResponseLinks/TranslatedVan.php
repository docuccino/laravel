<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that reads its own attributes, as a translations package does: what a column holds on the wire
 * is whatever that code returns. Never queried.
 *
 * @property int $id
 * @property string $plate
 */
final class TranslatedVan extends Model
{
    protected $guarded = [];

    public function getAttributeValue($key): mixed
    {
        return parent::getAttributeValue($key);
    }
}
