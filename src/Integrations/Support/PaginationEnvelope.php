<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * The JSON paginator envelopes Laravel serialises around a page of resource-collection items
 * (`{data, links, meta}`), shared by the integrations that document a Laravel-paginated collection
 * (API resources, `jsonPaginate`) so the wrapper shape stays identical whoever produces it. Three
 * modes are modelled — length-aware ({@see length()}), simple ({@see simple()}) and cursor
 * ({@see cursor()}); each builder takes the already-converted item schema and wraps it. `data`, `links`
 * and `meta` are always emitted (an empty page still carries them), so all three are required.
 *
 * This is Laravel's `AbstractPaginator` envelope; `spatie/laravel-data` uses a different one
 * ({@see SpatieDataEnvelope}) — the two are NOT interchangeable.
 */
final class PaginationEnvelope
{
    /**
     * The length-aware paginator shape (`paginate()`): first/last/prev/next `links` and a `meta`
     * block with the full page counters (it knows the total, hence `last_page`/`total`).
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
     * The simple paginator shape (`simplePaginate()`): it does NOT count the full result set, so
     * there is no `last` link and the `meta` block omits `last_page`/`total`.
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
     * The cursor paginator shape (`cursorPaginate()`): opaque `next_cursor`/`prev_cursor` tokens, no
     * page counters.
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
