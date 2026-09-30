<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\QueryBuilder;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A custom filter whose `#[QueryParameter]` leaves out `name`, as a filter class may: its parameter is
 * named by the `AllowedFilter` registration. The body filters on a string column (`title`), so an
 * `integer` published for it can only have come from the attribute.
 *
 * @implements Filter<Model>
 */
#[QueryParameter(type: 'int', description: 'Minimum band.')]
final class NamelessFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where('title', '>=', $value);
    }
}
