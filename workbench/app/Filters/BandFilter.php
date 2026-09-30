<?php

declare(strict_types=1);

namespace Workbench\App\Filters;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A Spatie custom filter documenting itself with a class-level `#[QueryParameter]` that leaves out the
 * name, as a filter class may: the `AllowedFilter` registration names the parameter.
 *
 * @implements Filter<Model>
 */
#[QueryParameter(type: 'int', description: 'The lowest score band to include.', example: 3)]
final class BandFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->whereRaw('score / 10 >= ?', [$value]);
    }
}
