<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Docuccino\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Enums\WidgetStatus;

/**
 * A model fixture exercising the Eloquent schema facts: `$hidden` + a class-level `#[Hidden]` list
 * (deny-list), `$appends` (accessor property), and `$casts` covering a datetime, a boolean, an array,
 * and a backed-enum cast (routed through the Enum integration). Only ever reflected — never queried.
 */
#[Hidden('token')]
final class Widget extends Model
{
    /**
     * @var list<string>
     */
    protected $hidden = ['password'];

    /**
     * @var list<string>
     */
    protected $appends = ['display_name'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'is_active' => 'boolean',
        'status' => WidgetStatus::class,
        'meta' => 'array',
    ];
}
