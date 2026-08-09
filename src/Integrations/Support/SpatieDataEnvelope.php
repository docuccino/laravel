<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Laravel\Integrations\SpatieData\DataSchema;

/**
 * The paginated envelopes `spatie/laravel-data` serialises around a page of Data items — distinct
 * from Laravel's own resource paginator envelope ({@see PaginationEnvelope}) and NOT interchangeable
 * with it (audit spatie-data gap 7). Mirrors spatie's `TransformedDataCollectableResolver`:
 *
 * - `links` is an ARRAY of `{url, label, active}` objects (spatie's `linkCollection()`), not a
 *   `{first,last,prev,next}` object; the cursor variant emits an empty `links` array.
 * - `meta` carries the `*_page_url` members alongside the counters (length-aware) / cursor tokens.
 * - all three of the items key / `links` / `meta` are always emitted, so all three are required.
 *
 * A paginated collection is ALWAYS wrapped: the items key is the wrap key (`config('data.wrap')`,
 * default `'data'`) — {@see DataSchema} passes it in.
 */
final class SpatieDataEnvelope
{
    /**
     * The length-aware paginated collection (`PaginatedDataCollection`).
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function length(array $items, string $dataKey = 'data'): array
    {
        return self::wrap($items, $dataKey, [
            'meta' => SchemaShorthand::object([
                'current_page' => ['type' => 'integer'],
                'first_page_url' => SchemaShorthand::nullableString(),
                'from' => SchemaShorthand::nullableInteger(),
                'last_page' => ['type' => 'integer'],
                'last_page_url' => SchemaShorthand::nullableString(),
                'next_page_url' => SchemaShorthand::nullableString(),
                'path' => SchemaShorthand::nullableString(),
                'per_page' => ['type' => 'integer'],
                'prev_page_url' => SchemaShorthand::nullableString(),
                'to' => SchemaShorthand::nullableInteger(),
                'total' => ['type' => 'integer'],
            ]),
        ]);
    }

    /**
     * The cursor-paginated collection (`CursorPaginatedDataCollection`): `links` is empty and `meta`
     * carries the opaque cursor tokens plus the neighbouring page URLs.
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function cursor(array $items, string $dataKey = 'data'): array
    {
        return self::wrap($items, $dataKey, [
            'meta' => SchemaShorthand::object([
                'path' => SchemaShorthand::nullableString(),
                'per_page' => ['type' => 'integer'],
                'next_cursor' => SchemaShorthand::nullableString(),
                'next_page_url' => SchemaShorthand::nullableString(),
                'prev_cursor' => SchemaShorthand::nullableString(),
                'prev_page_url' => SchemaShorthand::nullableString(),
            ]),
        ]);
    }

    /**
     * The items key (the page of items) + spatie's `links` array-of-objects + the given `meta` block;
     * all three keys are always serialised, so all three are required.
     *
     * @param  array<string, mixed>  $items
     * @param  array<string, array<string, mixed>>  $extra
     * @return array<string, mixed>
     */
    private static function wrap(array $items, string $dataKey, array $extra): array
    {
        return [
            'type' => 'object',
            'properties' => [
                $dataKey => ['type' => 'array', 'items' => $items],
                'links' => [
                    'type' => 'array',
                    'items' => SchemaShorthand::object([
                        'url' => SchemaShorthand::nullableString(),
                        'label' => ['type' => 'string'],
                        'active' => ['type' => 'boolean'],
                    ]),
                ],
            ] + $extra,
            'required' => [$dataKey, 'links', 'meta'],
        ];
    }
}
