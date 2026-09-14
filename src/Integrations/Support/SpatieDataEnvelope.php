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
 * Each part states what it is beside the shape it is; the page takes the sentence for its kind from
 * {@see PageComponent}.
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
        $built = self::builds($kind);

        return self::wrap($items, $dataKey, self::parts($built), PageComponent::description($built));
    }

    /**
     * The kind whose shape this builder actually produces for `$kind`. Spatie has no simple-paginator
     * collectable, so there is no simple envelope here and `simple` gets the length-aware one — the
     * sentence is taken for THIS kind rather than the one asked for, so a kind this builder has no arm
     * for cannot be handed prose about a shape it did not get.
     */
    public static function builds(string $kind): string
    {
        return $kind === 'cursor' ? 'cursor' : 'length';
    }

    /**
     * The envelope's non-item members for `$kind`, each with the component its shape publishes under.
     * The cursor variant swaps the counters for tokens; the page links are the same list either way.
     *
     * @return array<string, Part>
     */
    public static function parts(string $kind): array
    {
        $links = PaginationParts::part('PaginationLink', 'One entry in a page link list: the URL of that page, the label to show for it, and whether it is the page you are on.', SchemaShorthand::object([
            'url' => SchemaShorthand::nullableString(),
            'label' => ['type' => 'string'],
            'active' => ['type' => 'boolean'],
        ]), list: true);

        return match (self::builds($kind)) {
            'cursor' => [
                'links' => $links,
                'meta' => PaginationParts::part('DataCursorPaginationMeta', 'Where this page sits in a cursor-paginated result set: the page size, the base URL its page links are built from, and the cursor and URL for the next and previous pages — null where there is no page that way.', SchemaShorthand::object([
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
                'meta' => PaginationParts::part('DataPaginationMeta', 'Where this page sits in the result set: the page number and size, the number of the last page, the record total, the index of the first and last record on this page, the base URL its page links are built from, and a URL for the first, last, previous and next pages.', SchemaShorthand::object([
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
    private static function wrap(array $items, string $dataKey, array $parts, string $description): array
    {
        $properties = [$dataKey => ['type' => 'array', 'items' => $items]];
        foreach ($parts as $member => $part) {
            $properties[$member] = PaginationParts::inline($part);
        }

        return [
            'description' => $description,
            'type' => 'object',
            'properties' => $properties,
            'required' => [$dataKey, 'links', 'meta'],
        ];
    }
}
