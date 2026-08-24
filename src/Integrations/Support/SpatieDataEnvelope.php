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
 * Neither member matches the resource envelope's, so both name components of their own
 * ({@see PaginationParts}). The link object is unqualified — `PaginationLink` — because Laravel's
 * envelope has no such object to contest it; the two metas carry the `Data` qualifier because it does
 * publish metas, and a shared name over two different shapes would lie about one of them.
 *
 * All three keys are always serialised, so all three are required. A paginated collection is always
 * wrapped, and {@see DataSchema} passes the wrap key in as the items key.
 *
 * @phpstan-import-type Part from PaginationParts
 */
final class SpatieDataEnvelope
{
    /**
     * The envelope for `$kind` — `PaginatedDataCollection` (length-aware) or
     * `CursorPaginatedDataCollection`. Anything else gets the length-aware shape.
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function of(string $kind, array $items, string $dataKey = 'data'): array
    {
        return self::wrap($items, $dataKey, self::parts($kind));
    }

    /**
     * The envelope's non-item members for `$kind`, each with the component its shape publishes under.
     * The cursor variant swaps the counters for tokens; the page links are the same list either way.
     *
     * @return array<string, Part>
     */
    public static function parts(string $kind): array
    {
        $links = PaginationParts::part('PaginationLink', SchemaShorthand::object([
            'url' => SchemaShorthand::nullableString(),
            'label' => ['type' => 'string'],
            'active' => ['type' => 'boolean'],
        ]), list: true);

        return match ($kind) {
            'cursor' => [
                'links' => $links,
                'meta' => PaginationParts::part('DataCursorPaginationMeta', SchemaShorthand::object([
                    'path' => SchemaShorthand::nullableString(),
                    'per_page' => ['type' => 'integer'],
                    'next_cursor' => SchemaShorthand::nullableString(),
                    'next_page_url' => SchemaShorthand::nullableString(),
                    'prev_cursor' => SchemaShorthand::nullableString(),
                    'prev_page_url' => SchemaShorthand::nullableString(),
                ])),
            ],
            default => [
                'links' => $links,
                'meta' => PaginationParts::part('DataPaginationMeta', SchemaShorthand::object([
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
                ])),
            ],
        };
    }

    /**
     * The items key under the wrap key, then the envelope's declared members.
     *
     * @param  array<string, mixed>  $items
     * @param  array<string, Part>  $parts
     * @return array<string, mixed>
     */
    private static function wrap(array $items, string $dataKey, array $parts): array
    {
        $properties = [$dataKey => ['type' => 'array', 'items' => $items]];
        foreach ($parts as $member => $part) {
            $properties[$member] = PaginationParts::inline($part);
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => [$dataKey, 'links', 'meta'],
        ];
    }
}
