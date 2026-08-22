<?php

declare(strict_types=1);

namespace Workbench\App\Filters;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A shared filter whose static factory BRANCHES — no single return to fold — so its class-level
 * `#[QueryParameter]` (format alone, no type) is the only static answer for the entries it builds.
 * Only ever analysed.
 *
 * @implements Filter<Model>
 */
#[QueryParameter(name: 'ignored', format: 'ulid', description: 'A legacy ulid key.')]
final class LegacyKeyFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where($property, is_string($value) ? $value : '');
    }

    /** Two arms — deliberately unfoldable, which is exactly what the fallback path exists for. */
    public static function allowed(string $key, bool $strict = true): AllowedFilter
    {
        if ($strict) {
            return AllowedFilter::custom($key, new self, $key);
        }

        return AllowedFilter::partial($key);
    }
}
