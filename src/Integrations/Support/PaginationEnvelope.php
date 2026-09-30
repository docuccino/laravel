<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Laravel\Integrations\ApiResources\CollectionKeys;
use Docuccino\Laravel\Integrations\ApiResources\ToArrayObject;

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
 * ({@see SpatieDataEnvelope}); the two are NOT interchangeable. A collection whose
 * `paginationInformation()` drops the null page links — timacdonald/json-api's — sends the same envelope
 * with only the links that exist, so it is built here too, with its own links part. Which of the two a
 * page sends is a required argument of every builder ({@see PageLinks}).
 *
 * @phpstan-import-type Part from PaginationParts
 */
final class PaginationEnvelope
{
    /** What `array_merge_recursive` sends for a key both sides send: an array, a list or not. */
    public const MERGED = ['type' => ['array', 'object']];

    /**
     * The envelope for `$kind`. An unknown kind gets the length-aware shape — the paginator an
     * application reaches for unless it says otherwise. A page whose collection preserves its keys sends
     * its data as the array or object they make ({@see CollectionKeys}).
     *
     * @param  array<array-key, mixed>  $items
     * @return array<string, mixed>
     */
    public static function of(string $kind, array $items, PageLinks $links, bool $preservedKeys = false): array
    {
        $built = self::builds($kind);

        return self::wrap(CollectionKeys::sent($items, $preservedKeys), self::parts($built, $links), PageComponent::description($built));
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
     * Where the null links are dropped, each link is sent only where there is such a page: the first and
     * last of a counted result set always, the first of an uncounted one, and never a cursor page's first
     * or last, which Laravel leaves null.
     *
     * @return array<string, Part>
     */
    public static function parts(string $kind, PageLinks $links): array
    {
        if ($links === PageLinks::Available) {
            return ['links' => self::availableLinks(self::builds($kind)), 'meta' => self::parts($kind, PageLinks::Laravel)['meta']];
        }

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
     * Every key Laravel sends in each part for `$kind`, as what it sends there — which can be more than the
     * part names: the links are always all four, null where there is no such page, and the meta is the
     * paginator's `toArray()` less `data` and the page URLs.
     *
     * @return array{links: array<string, array<string, mixed>>, meta: array<string, array<string, mixed>>}
     */
    public static function sent(string $kind, PageLinks $pageLinks): array
    {
        $built = self::builds($kind);
        $parts = self::parts($built, $pageLinks);
        $named = static fn (string $part): array => self::named($parts[$part]['schema']);

        // What each kind sends beyond what its part names.
        [$links, $meta] = match ($built) {
            'simple' => [['last' => ['type' => 'null']], ['current_page_url' => ['type' => 'string']]],
            'cursor' => [[], []],
            default => [[], ['links' => ['type' => 'array', 'items' => ['type' => 'object']]]],
        };

        /** @var array<string, array<string, mixed>> $links */
        $links = $pageLinks === PageLinks::Laravel ? [...$named('links'), ...$links] : $named('links');
        /** @var array<string, array<string, mixed>> $meta */
        $meta = [...$named('meta'), ...$meta];

        return ['links' => $links, 'meta' => $meta];
    }

    /**
     * The keys of each part sent on every page of `$kind` — all of them where Laravel sends its links, and
     * only the links there is always a page for where the null ones are dropped. A part with none may be
     * sent as `[]`.
     *
     * @return array{links: list<string>, meta: list<string>}
     */
    public static function alwaysSent(string $kind, PageLinks $pageLinks): array
    {
        $sent = self::sent($kind, $pageLinks);
        $links = self::parts($kind, $pageLinks)['links']['schema'];
        $object = self::object($links);
        $required = is_array($object['required'] ?? null) ? array_values(array_filter($object['required'], is_string(...))) : [];

        return [
            'links' => $pageLinks === PageLinks::Laravel ? array_keys($sent['links']) : $required,
            'meta' => array_keys($sent['meta']),
        ];
    }

    /**
     * The properties a part names, read through the empty array it may also be sent as.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function named(array $schema): array
    {
        $properties = self::object($schema)['properties'] ?? null;

        return is_array($properties) ? array_filter($properties, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * `with()`'s members as they are sent when the collection may or may not hold a paginator, whose `links`
     * and `meta` are merged with them by `array_merge_recursive`: a member under either name is its own
     * value or an object the page's joins, and a key both send is its own value or the array the two are
     * merged into. A part whose keys are not all named is widened to any object, since the page's keys
     * join it whatever it says of the rest.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function mayMergeInto(array $properties): array
    {
        foreach (['links', 'meta'] as $part) {
            $member = $properties[$part] ?? null;
            if (! is_array($member)) {
                continue;
            }

            if (($member['type'] ?? null) !== 'object') {
                $properties[$part] = ['anyOf' => [$member, ['type' => 'object']]];

                continue;
            }

            if (array_key_exists('patternProperties', $member) || (array_key_exists('additionalProperties', $member) && $member['additionalProperties'] !== false)) {
                unset($member['patternProperties'], $member['additionalProperties']);
            }

            $named = is_array($member['properties'] ?? null) ? $member['properties'] : [];
            $sent = [];
            foreach (['length', 'simple', 'cursor'] as $kind) {
                foreach (PageLinks::cases() as $pageLinks) {
                    $sent = [...$sent, ...self::sent($kind, $pageLinks)[$part]];
                }
            }
            foreach (array_intersect_key($named, $sent) as $key => $schema) {
                $named[$key] = ['anyOf' => [$schema, self::MERGED]];
            }
            if ($named !== []) {
                $member['properties'] = $named;
            }
            $properties[$part] = $member;
        }

        return $properties;
    }

    /**
     * The links part of a page that sends only the links there is a page for. A cursor page has no first
     * or last link, so one with no page either side sends none — the empty array `[]`, not `{}`.
     *
     * @return Part
     */
    private static function availableLinks(string $built): array
    {
        $url = ['type' => 'string'];

        return match ($built) {
            'simple' => PaginationParts::part('AvailableSimplePaginationLinks', 'URLs for the first page of this result set, and for the previous and next pages where there is one; the result set is never counted, so there is no last page to link to.', [
                ...SchemaShorthand::object(['first' => $url, 'prev' => $url, 'next' => $url]),
                'required' => ['first'],
            ]),
            'cursor' => PaginationParts::part('AvailableCursorPaginationLinks', 'URLs for the previous and next pages of this result set, where there is one; an empty list where there is neither.', ToArrayObject::orEmpty(SchemaShorthand::object(['prev' => $url, 'next' => $url]))),
            default => PaginationParts::part('AvailablePaginationLinks', 'URLs for the first and last pages of this result set, and for the previous and next pages where there is one.', [
                ...SchemaShorthand::object(['first' => $url, 'last' => $url, 'prev' => $url, 'next' => $url]),
                'required' => ['first', 'last'],
            ]),
        };
    }

    /**
     * A part's object shape: itself, or the object alternative beside the empty array it may be sent as.
     *
     * @param  array<string, mixed>  $schema
     * @return array<array-key, mixed>
     */
    private static function object(array $schema): array
    {
        foreach (is_array($schema['anyOf'] ?? null) ? $schema['anyOf'] : [] as $alternative) {
            if (is_array($alternative) && ($alternative['type'] ?? null) === 'object') {
                return $alternative;
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Part>  $parts
     * @return array<string, mixed>
     */
    private static function wrap(array $data, array $parts, string $description): array
    {
        $properties = ['data' => $data];
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
