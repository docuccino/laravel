<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Docuccino\Laravel\Integrations\Eloquent\CastSchema;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Enums\WidgetStatus;

/**
 * A model fixture whose `$casts` cover every kind the Query Builder filter-column resolver maps: a
 * backed enum, the native scalar casts ({@see CastSchema}),
 * an unrecognised custom caster, and a no-cast column. Only ever reflected — never queried.
 */
final class FilterCastModel extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => WidgetStatus::class,
        'active' => 'boolean',
        'quantity' => 'integer',
        'rating' => 'float',
        'published_at' => 'datetime',
        'archived_on' => 'immutable_date',
        'price' => 'decimal:2',
        'nickname' => 'string',
        'custom' => CustomCaster::class,
    ];
}
