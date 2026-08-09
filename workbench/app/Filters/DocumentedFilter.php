<?php

declare(strict_types=1);

namespace Workbench\App\Filters;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A Spatie custom filter documenting itself with a class-level `#[QueryParameter]` — the explicit
 * override the Query Builder integration applies instead of inferring from the (deliberately opaque)
 * `__invoke` body. The attribute `name` is ignored in this position (the parameter name is the
 * `AllowedFilter` name).
 *
 * @implements Filter<Model>
 */
#[QueryParameter(name: 'ignored', type: 'int', description: 'Minimum popularity score.', example: 42)]
final class DocumentedFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->whereRaw('popularity(?) >= score', [$value]);
    }
}
