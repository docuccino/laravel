<?php

declare(strict_types=1);

namespace Workbench\App\Filters;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A shared custom filter registered through its own static factory (`PublicIdFilter::allowed('key')`) —
 * the reusable-filter idiom — declaring its schema ONCE with a class-level `#[QueryParameter]`. The
 * runtime enforces the uuid shape (422 on a malformed value); the attribute is what publishes it. The
 * attribute `name` is ignored in this position (the parameter name is the `AllowedFilter` name).
 *
 * @implements Filter<Model>
 */
#[QueryParameter(name: 'ignored', type: 'string', format: 'uuid', description: 'A uuid public identifier.')]
final class PublicIdFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ValidationException::withMessages([$property => 'Must be a uuid.']);
        }

        $query->where($property, $value);
    }

    /** The factory every call site uses, wrapping the Spatie registration in one place. */
    public static function allowed(string $key, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::custom($key, new self, $column ?? $key);
    }
}
