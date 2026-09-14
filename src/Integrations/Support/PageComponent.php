<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Support\Fqcn;

/**
 * Hoists a page-of-X envelope to ONE reusable component per item type and paginator kind, so every
 * paginated operation `$ref`s that instead of restating the whole envelope. Shared by every producer
 * that builds one — Laravel's envelope ({@see PaginationEnvelope}) and spatie's
 * ({@see SpatieDataEnvelope}) — because a page named one way by one producer and another way by
 * another is two vocabularies in one document.
 *
 * The name is the ITEM's own component ask plus the kind expressed as a FACET of the item's identity
 * (`…\ArticleResource#page` → `ArticleResourcePage`, `#cursorPage` → `ArticleResourceCursorPage`).
 * That is the same rung-1 ask a class's request shape makes beside its response shape, so a page of a
 * class is derived from the class and the kind alone — never from which route reached it first — two
 * operations paginating one type land on one component, and `ComponentNames` settles the rest.
 *
 * Two things keep an envelope INLINE. An item type nothing identified has no name to be derived
 * from, and one whose schema is not already a component of its own would put the quality of a
 * conversion into the component's bytes — two routes converting one class differently would then
 * publish one body under one identity, and the registry dedupes on identity. Both keep the envelope on
 * the operation: vague, but true.
 *
 * Its `links`/`meta` are hoisted either way ({@see PaginationParts}). Neither reason above reaches them:
 * those shapes are a function of the paginator kind alone, so they are the same bytes whether the item
 * type was named or converted well, and an envelope stuck on the operation duplicates them just as
 * badly as a component would.
 *
 * What a page SAYS is a function of the kind alone too, so the sentence lives here beside the facet
 * rather than in either envelope builder — both build a page of the same kind and would otherwise drift
 * apart a sentence at a time. Deriving it from the item instead would couple a published sentence to a
 * name-minting decision, and renaming one class would then rewrite prose on a page of another.
 *
 * @phpstan-type Kind array{facet: string, description: string}
 */
final class PageComponent
{
    /** The prefix a hoisted item schema's `$ref` carries, and the only shape this will wrap. */
    private const SCHEMAS = '#/components/schemas/';

    /**
     * Paginator kind → the facet of the item's identity a page of it is, and what a page of it tells
     * the consumer. One row per kind, so the name and the sentence cannot answer for different sets.
     * The length-aware facet is plain `page`: it is the kind an application reaches for unless it says
     * otherwise, and the qualified names read as the departures they are.
     *
     * @var array<string, Kind>
     */
    private const KINDS = [
        'length' => [
            'facet' => 'page',
            'description' => 'One page of results, with links to the pages around it and totals for the whole result set.',
        ],
        'simple' => [
            'facet' => 'simplePage',
            'description' => 'One page of results, with links to the pages around it; the result set is never counted, so it carries no totals.',
        ],
        'cursor' => [
            'facet' => 'cursorPage',
            'description' => 'One page of results, with the cursors that fetch the pages either side of it.',
        ],
    ];

    /**
     * The sentence a page of `$kind` publishes. An unknown kind gets the length-aware one, because that
     * is the SHAPE both envelope builders give it — a sentence describing some other shape would be the
     * confident lie a vague answer exists to avoid.
     */
    public static function description(string $kind): string
    {
        return (self::KINDS[$kind] ?? self::KINDS['length'])['description'];
    }

    /**
     * The `{"$ref": …}` a paginated body may point at instead of carrying `$envelope`, or null when
     * the envelope has to stay inline.
     *
     * @param  ?string  $itemFqcn  the item class, or null when nothing identified one
     * @param  array<array-key, mixed>  $items  the item schema the producer put in the envelope
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>|null
     */
    public static function reference(SchemaContext $context, string $kind, ?string $itemFqcn, array $items, array $envelope): ?array
    {
        $facet = self::KINDS[$kind]['facet'] ?? null;
        if ($facet === null || $itemFqcn === null || ! $context->representation()->paginationComponents) {
            return null;
        }

        if (! self::isComponentRef($items)) {
            return null;
        }

        return $context->reference(
            SchemaIdentity::name($itemFqcn) ?? Fqcn::short($itemFqcn),
            $envelope,
            SchemaIdentity::publishedId($itemFqcn, $facet),
        );
    }

    /**
     * Whether an item schema is nothing but a pointer at a component — the one shape whose bytes are
     * a function of the item's identity rather than of how well it converted.
     *
     * @param  array<array-key, mixed>  $items
     */
    private static function isComponentRef(array $items): bool
    {
        $ref = $items['$ref'] ?? null;

        return count($items) === 1 && is_string($ref) && str_starts_with($ref, self::SCHEMAS);
    }
}
