<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * The `{data, links, meta}` envelopes Laravel serialises around a page of items, shared by every
 * integration that documents a Laravel-paginated collection. Each builder wraps an already-converted item
 * schema. All three members are always emitted — an empty page still carries them — so all three are
 * required.
 *
 * This is Laravel's `AbstractPaginator` envelope. `spatie/laravel-data` has its own
 * ({@see SpatieDataEnvelope}); the two are NOT interchangeable.
 */
final class PaginationEnvelope
{
    /**
     * `paginate()`: first/last/prev/next links, and a meta block with the full counters — it counts the
     * result set, so it knows `last_page`/`total`.
     *
     * @param  array<array-key, mixed>  $items
     * @return array<string, mixed>
     */
    public static function length(array $items): array
    {
        return self::wrap($items, [
            'first' => SchemaShorthand::nullableString(),
            'last' => SchemaShorthand::nullableString(),
            'prev' => SchemaShorthand::nullableString(),
            'next' => SchemaShorthand::nullableString(),
        ], [
            'current_page' => ['type' => 'integer'],
            'from' => SchemaShorthand::nullableInteger(),
            'last_page' => ['type' => 'integer'],
            'path' => SchemaShorthand::nullableString(),
            'per_page' => ['type' => 'integer'],
            'to' => SchemaShorthand::nullableInteger(),
            'total' => ['type' => 'integer'],
        ]);
    }

    /**
     * `simplePaginate()`: no count of the result set, so no `last` link and no `last_page`/`total`.
     *
     * @param  array<array-key, mixed>  $items
     * @return array<string, mixed>
     */
    public static function simple(array $items): array
    {
        return self::wrap($items, [
            'first' => SchemaShorthand::nullableString(),
            'prev' => SchemaShorthand::nullableString(),
            'next' => SchemaShorthand::nullableString(),
        ], [
            'current_page' => ['type' => 'integer'],
            'from' => SchemaShorthand::nullableInteger(),
            'path' => SchemaShorthand::nullableString(),
            'per_page' => ['type' => 'integer'],
            'to' => SchemaShorthand::nullableInteger(),
        ]);
    }

    /**
     * `cursorPaginate()`: opaque `next_cursor`/`prev_cursor` tokens instead of page counters.
     *
     * @param  array<array-key, mixed>  $items
     * @return array<string, mixed>
     */
    public static function cursor(array $items): array
    {
        return self::wrap($items, [
            'first' => SchemaShorthand::nullableString(),
            'last' => SchemaShorthand::nullableString(),
            'prev' => SchemaShorthand::nullableString(),
            'next' => SchemaShorthand::nullableString(),
        ], [
            'path' => SchemaShorthand::nullableString(),
            'per_page' => ['type' => 'integer'],
            'next_cursor' => SchemaShorthand::nullableString(),
            'prev_cursor' => SchemaShorthand::nullableString(),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @param  array<string, array<string, mixed>>  $links
     * @param  array<string, array<string, mixed>>  $meta
     * @return array<string, mixed>
     */
    private static function wrap(array $items, array $links, array $meta): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => $items],
                'links' => SchemaShorthand::object($links),
                'meta' => SchemaShorthand::object($meta),
            ],
            'required' => ['data', 'links', 'meta'],
        ];
    }
}
