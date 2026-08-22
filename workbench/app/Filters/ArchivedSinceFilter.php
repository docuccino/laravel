<?php

declare(strict_types=1);

namespace Workbench\App\Filters;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A shared filter whose class-level attribute writes BOTH a type and a format the type does not imply
 * (`date` implies `format: date`; the attribute pins `date-time`). Only ever analysed.
 *
 * @implements Filter<Model>
 */
#[QueryParameter(name: 'ignored', type: 'date', format: 'date-time', description: 'Archived at or after this instant.')]
final class ArchivedSinceFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where($property, '>=', is_string($value) ? $value : '');
    }
}
