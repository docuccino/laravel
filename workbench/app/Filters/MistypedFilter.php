<?php

declare(strict_types=1);

namespace Workbench\App\Filters;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A Spatie custom filter whose `#[QueryParameter]` PHP cannot construct — a list where the attribute
 * takes a string — over a body that filters on one column.
 *
 * @implements Filter<Model>
 */
#[QueryParameter(type: ['int'], description: 'Never published.')]
final class MistypedFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where('score', $value);
    }
}
