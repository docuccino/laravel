<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Laravel\Integrations\SpatieData\DataSchema;

/**
 * The paginated envelopes `spatie/laravel-data` serialises around a page of Data items, mirroring
 * spatie's `TransformedDataCollectableResolver`. NOT interchangeable with Laravel's own resource
 * envelope ({@see PaginationEnvelope}) — the differences that matter:
 *
 * - `links` is an ARRAY of `{url, label, active}` objects (spatie's `linkCollection()`), not a
 *   `{first,last,prev,next}` object; the cursor variant emits an empty array.
 * - `meta` carries `*_page_url` members alongside the counters or cursor tokens.
 *
 * All three keys are always serialised, so all three are required. A paginated collection is always
 * wrapped, and {@see DataSchema} passes the wrap key in as the items key.
 */
final class SpatieDataEnvelope
{
    /**
     * `PaginatedDataCollection` — the length-aware variant, with full counters.
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
     * `CursorPaginatedDataCollection` — `links` is empty; `meta` carries the cursor tokens and the
     * neighbouring page URLs.
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
     * The items key, spatie's array-of-objects `links`, and the given `meta` block.
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
