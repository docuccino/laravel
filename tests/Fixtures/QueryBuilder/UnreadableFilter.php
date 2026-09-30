<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\QueryBuilder;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A custom filter whose `#[QueryParameter]` PHP cannot construct — a list where the attribute takes a
 * string — over a body that filters on one column (`score`). Fixing the attribute is an edit to this
 * file.
 *
 * @implements Filter<Model>
 */
#[QueryParameter(type: ['int'])]
final class UnreadableFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where('score', '>=', $value);
    }
}
