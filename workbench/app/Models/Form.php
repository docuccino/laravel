<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Workbench\App\Enums\WidgetStatus;

/**
 * A minimal Eloquent model used for route-model binding in the workbench routes and as the subject of
 * the Query Builder list route; it is never queried (the pipeline reflects the bound type + its
 * `$casts`, it does not dispatch the route). The `status` enum cast lets the Query Builder integration
 * type the `filter[status]` parameter from the model's own cast.
 */
final class Form extends Model
{
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => WidgetStatus::class,
    ];
}
