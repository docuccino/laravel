<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Closure;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use WeakMap;

/**
 * What the root collection of one conversion wraps, where the caller proved it and the type cannot say:
 * `Resource::collection()` is typed alike whatever it is handed. Laravel's `collectResource()` leaves
 * `$this->resource` the paginator a page was built from, or else the plain collection it built, so a
 * collection's `with()` is read for the one envelope it is sent in ({@see JsonResourceSchema}). Held per
 * converter for the length of one conversion; outside one, nothing is known and `with()` is read whole.
 */
final class WrappedResource
{
    /**
     * What `$this->resource` is on an unpaginated list: `collectResource()` collects an array into, and
     * takes an Eloquent collection to the base of, this class itself.
     */
    public const PLAIN = 'Illuminate\\Support\\Collection';

    /** The paginator each page kind is built as — the classes Laravel's paginating terminals construct. */
    public const PAGINATORS = [
        'length' => 'Illuminate\\Pagination\\LengthAwarePaginator',
        'simple' => 'Illuminate\\Pagination\\Paginator',
        'cursor' => 'Illuminate\\Pagination\\CursorPaginator',
    ];

    /** @var WeakMap<SchemaContext, string>|null */
    private static ?WeakMap $wrapping = null;

    /**
     * Runs `$convert` with the root collection known to wrap an instance of `$class`.
     *
     * @template T
     *
     * @param  Closure(): T  $convert
     * @return T
     */
    public static function during(SchemaContext $context, string $class, Closure $convert): mixed
    {
        $wrapping = self::$wrapping ??= new WeakMap;
        $previous = $wrapping[$context] ?? null;
        $wrapping[$context] = $class;

        try {
            return $convert();
        } finally {
            if ($previous === null) {
                unset($wrapping[$context]);
            } else {
                $wrapping[$context] = $previous;
            }
        }
    }

    /** The class the root collection being converted wraps, or null where no caller proved one. */
    public static function of(SchemaContext $context): ?string
    {
        return self::$wrapping[$context] ?? null;
    }
}
