<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Workbench\App\Enums\WidgetStatus;

/**
 * A workbench model exercising the Query Builder filter-kind inference (scope value parameter typing,
 * a callback/operator/custom column cast). Never queried — the pipeline only reflects its `$casts` and
 * scope-method signatures. Not referenced by any golden route, so it is free to carry test-only casts
 * and scopes.
 *
 * @property WidgetStatus $status
 * @property bool $active
 * @property int $score
 * @property string $public_id
 * @property Carbon $starts_at
 */
final class Gadget extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => WidgetStatus::class,
        'active' => 'boolean',
        'score' => 'integer',
        // A string-cast identifier column, so a `FilterFactory::uuid()` filter types off the cast the
        // same way the boolean arm does (and is provably NOT left as an untyped default).
        'public_id' => 'string',
        // A datetime cast: the one whose schema (`format: date-time`) is DISTINGUISHABLE from the
        // plain-string fallback, so a filter typed off a declared column provably resolved the cast.
        'starts_at' => 'datetime',
    ];

    /**
     * A scope whose value parameter is a backed enum — the scope filter types off it.
     *
     * @param  Builder<Gadget>  $query
     */
    public function scopeStatus(Builder $query, WidgetStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * A scope whose value parameter is a native int.
     *
     * @param  Builder<Gadget>  $query
     */
    public function scopeMinScore(Builder $query, int $score): void
    {
        $query->where('score', '>=', $score);
    }

    /**
     * A scope with no value parameter — the scope filter stays a plain string.
     *
     * @param  Builder<Gadget>  $query
     */
    public function scopePopular(Builder $query): void
    {
        $query->where('score', '>=', 100);
    }
}
