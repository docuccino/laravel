<?php

declare(strict_types=1);

namespace Workbench\App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A Spatie custom filter whose `__invoke` body is a single `where` on a literal column — the column
 * the Query Builder integration recovers to type the filter off the model cast.
 *
 * @implements Filter<Model>
 */
final class ScoreFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where('score', $value);
    }
}
