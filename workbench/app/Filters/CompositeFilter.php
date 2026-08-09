<?php

declare(strict_types=1);

namespace Workbench\App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A Spatie custom filter whose `__invoke` body is too complex to recover a single column from (two
 * `where` clauses) — the integration bails to a plain string, silently, with no attribute to override.
 *
 * @implements Filter<Model>
 */
final class CompositeFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where('score', $value);
        $query->orWhere('active', true);
    }
}
