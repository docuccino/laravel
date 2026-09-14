<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * The `{data, links, meta}` envelopes Laravel serialises around a page of items, shared by every
 * integration that documents a Laravel-paginated collection. Each builder wraps an already-converted item
 * schema. All three members are always emitted — an empty page still carries them — so all three are
 * required.
 *
 * `links` and `meta` are a function of the paginator kind alone, so they are declared as named parts
 * ({@see PaginationParts}) and hoisted to one component per shape; only `data` is per item type. Each
 * part states what it is beside the shape it is, and the page itself takes the sentence for its kind
 * from {@see PageComponent}.
 *
 * This is Laravel's `AbstractPaginator` envelope. `spatie/laravel-data` has its own
 * ({@see SpatieDataEnvelope}); the two are NOT interchangeable.
 *
 * @phpstan-import-type Part from PaginationParts
 */
final class PaginationEnvelope
{
    /**
     * The envelope for `$kind`. An unknown kind gets the length-aware shape — the paginator an
     * application reaches for unless it says otherwise.
     *
     * @param  array<array-key, mixed>  $items
     * @return array<string, mixed>
     */
    public static function of(string $kind, array $items): array
    {
        $built = self::builds($kind);

        return self::wrap($items, self::parts($built), PageComponent::description($built));
    }

    /**
     * The kind whose shape this builder actually produces for `$kind`. The sentence is taken for THIS
     * kind rather than the one asked for, so a kind the document can describe but this builder has no
     * arm for cannot be handed prose about a shape it did not get.
     */
    public static function builds(string $kind): string
    {
        return match ($kind) {
            'simple', 'cursor' => $kind,
            default => 'length',
        };
    }

    /**
     * The envelope's non-item members for `$kind`, each with the component its shape publishes under.
     *
     * - `paginate()` counts the result set, so it knows `last_page`/`total` and has a `last` link.
     * - `simplePaginate()` counts nothing, so no `last` link and no `last_page`/`total`.
     * - `cursorPaginate()` carries opaque tokens instead of page counters — but the same four links as
     *   the length-aware page, which is why both name that object `PaginationLinks`.
     *
     * @return array<string, Part>
     */
    public static function parts(string $kind): array
    {
        $pageLinks = PaginationParts::part('PaginationLinks', 'URLs for the first, last, previous and next pages of this result set; null where there is no such page.', SchemaShorthand::object([
            'first' => SchemaShorthand::nullableString(),
            'last' => SchemaShorthand::nullableString(),
            'prev' => SchemaShorthand::nullableString(),
            'next' => SchemaShorthand::nullableString(),
        ]));

        return match (self::builds($kind)) {
            'simple' => [
                'links' => PaginationParts::part('SimplePaginationLinks', 'URLs for the first, previous and next pages of this result set; the result set is never counted, so there is no last page to link to.', SchemaShorthand::object([
                    'first' => SchemaShorthand::nullableString(),
                    'prev' => SchemaShorthand::nullableString(),
                    'next' => SchemaShorthand::nullableString(),
                ])),
                'meta' => PaginationParts::part('SimplePaginationMeta', 'Where this page sits in the result set: the page number and size, the index of the first and last record on it, and the base URL its page links are built from. Nothing counts the result set, so there is no record total.', SchemaShorthand::object([
                    'current_page' => ['type' => 'integer'],
                    'from' => SchemaShorthand::nullableInteger(),
                    'path' => SchemaShorthand::nullableString(),
                    'per_page' => ['type' => 'integer'],
                    'to' => SchemaShorthand::nullableInteger(),
                ])),
            ],
            'cursor' => [
                'links' => $pageLinks,
                'meta' => PaginationParts::part('CursorPaginationMeta', 'Where this page sits in a cursor-paginated result set: the page size, the base URL its page links are built from, and the cursors addressing the next and previous pages — null where there is no page that way.', SchemaShorthand::object([
                    'path' => SchemaShorthand::nullableString(),
                    'per_page' => ['type' => 'integer'],
                    'next_cursor' => SchemaShorthand::nullableString(),
                    'prev_cursor' => SchemaShorthand::nullableString(),
                ])),
            ],
            default => [
                'links' => $pageLinks,
                'meta' => PaginationParts::part('PaginationMeta', 'Where this page sits in the result set: the page number and size, the number of the last page, the record total, the index of the first and last record on this page, and the base URL its page links are built from.', SchemaShorthand::object([
                    'current_page' => ['type' => 'integer'],
                    'from' => SchemaShorthand::nullableInteger(),
                    'last_page' => ['type' => 'integer'],
                    'path' => SchemaShorthand::nullableString(),
                    'per_page' => ['type' => 'integer'],
                    'to' => SchemaShorthand::nullableInteger(),
                    'total' => ['type' => 'integer'],
                ])),
            ],
        };
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @param  array<string, Part>  $parts
     * @return array<string, mixed>
     */
    private static function wrap(array $items, array $parts, string $description): array
    {
        $properties = ['data' => ['type' => 'array', 'items' => $items]];
        foreach ($parts as $member => $part) {
            $properties[$member] = PaginationParts::inline($part);
        }

        return [
            'description' => $description,
            'type' => 'object',
            'properties' => $properties,
            'required' => ['data', 'links', 'meta'],
        ];
    }
}
