<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Workbench\App\Enums\WidgetStatus;

/**
 * A model whose cast columns are NULLABLE — an enum cast (whose schema is a `$ref`, the one fragment
 * that cannot carry `type: [x, null]`), a json cast (a type LIST), and a datetime cast beside them.
 * Documented idiomatically with `@property` tags; only ever reflected.
 *
 * @property int $id The consignment id.
 * @property WidgetStatus|null $status Its state, or null before dispatch.
 * @property array<string, mixed>|null $manifest The declared contents, or null while unsealed.
 * @property string|null $sealed_at When it was sealed, or null while open.
 */
final class Consignment extends Model
{
    use SoftDeletes;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => WidgetStatus::class,
        'manifest' => 'array',
        'sealed_at' => 'datetime',
    ];
}
