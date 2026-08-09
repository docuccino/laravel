<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Workbench\App\Enums\WidgetStatus;

/**
 * A model declaring its casts via the Laravel 11+ `casts()` METHOD (the default skeleton style),
 * which reflection of default property values cannot see — the cast map is recovered by statically
 * reading the method's literal return array. Documented idiomatically with `@property` tags; only
 * ever reflected.
 *
 * @property int $id The invoice id.
 * @property int $amount The amount in minor units.
 */
final class Invoice extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'meta' => 'array',
            'status' => WidgetStatus::class,
        ];
    }
}
