<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\QueryBuilder;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A custom filter whose `#[QueryParameter]` leaves out the required `name`, so the attribute cannot be
 * built and the reader gets nothing from it. Fixing the attribute is an edit to this file.
 *
 * @implements Filter<Model>
 */
#[QueryParameter(type: 'int')]
final class NamelessFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where('score', '>=', $value);
    }
}
