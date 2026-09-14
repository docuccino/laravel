<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Integrations\Support\PageComponent;
use Docuccino\Laravel\Integrations\Support\PaginationEnvelope;
use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;
use Docuccino\Laravel\Integrations\Support\SpatieDataEnvelope;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;

/**
 * The kind → name and kind → sentence tables {@see PageComponent} publishes through, and the four
 * things that send an envelope back inline. No mappers are needed: the class asks the context for the
 * policy and hands it a body, so a converter over an empty chain is the whole collaboration.
 */
$converter = static fn (bool $hoist = true): SchemaConverter => new SchemaConverter(
    [],
    new NullTypeEngine,
    new ComponentRegistry,
    new RepresentationPolicy(paginationComponents: $hoist),
);

$items = ['$ref' => '#/components/schemas/ArticleResource'];

/** Both producers, crossed with every kind the terminal table can report. */
$envelopes = [
    'laravel length' => ['laravel', 'length'],
    'laravel simple' => ['laravel', 'simple'],
    'laravel cursor' => ['laravel', 'cursor'],
    'data length' => ['data', 'length'],
    'data simple' => ['data', 'simple'],
    'data cursor' => ['data', 'cursor'],
];

$parts = static fn (string $producer, string $kind): array => $producer === 'laravel'
    ? PaginationEnvelope::parts($kind)
    : SpatieDataEnvelope::parts($kind);

$envelope = static fn (string $producer, string $kind, array $items): array => $producer === 'laravel'
    ? PaginationEnvelope::of($kind, $items)
    : SpatieDataEnvelope::of($kind, $items);

it('names a page of an item type after the item and the kind', function (string $kind, string $expected) use ($converter, $items): void {
    $context = $converter();
    $envelope = PaginationEnvelope::of($kind, $items);

    // The item is a component of its own in any real build, so it is registered here too — the page
    // claims its name beside the item's, and may never be the thing that moves it.
    $context->reference('ArticleResource', ['type' => 'object'], ArticleResource::class);

    $reference = PageComponent::reference($context, $kind, ArticleResource::class, $items, $envelope);
    $slot = substr((string) ($reference['$ref'] ?? ''), strlen('#/components/schemas/'));

    // A registration slot is first-come; the name the document publishes is what the claims settle to.
    $renames = $context->components()->schemaRenames();

    expect($renames[$slot] ?? $slot)->toBe($expected)
        ->and($renames)->not->toHaveKey('ArticleResource')
        // The registered body is the envelope itself, item `$ref` and all — the component IS the page.
        ->and($context->components()->schemas()[$slot])->toBe($envelope);
})->with([
    'length' => ['length', 'ArticleResourcePage'],
    'simple' => ['simple', 'ArticleResourceSimplePage'],
    'cursor' => ['cursor', 'ArticleResourceCursorPage'],
]);

it('covers every paginator kind the terminal table can report', function () use ($converter, $items): void {
    // The dataset above only proves the rows it lists. This reads the source of truth — a kind nothing
    // here knows about would leave those endpoints inline for good with the suite still green.
    $kinds = array_values(array_unique(PaginationTerminalVisitor::PAGINATOR_TERMINALS));

    expect($kinds)->toHaveCount(3);

    foreach ($kinds as $kind) {
        expect(PageComponent::reference($converter(), $kind, ArticleResource::class, $items, PaginationEnvelope::of('length', $items)))
            ->not->toBeNull();
    }
});

it('leaves an envelope inline where it cannot name one', function (string $case) use ($converter, $items): void {
    $envelope = PaginationEnvelope::of('length', $items);

    $reference = match ($case) {
        // A kind outside the table: better no component than one named after a guess.
        'unknown kind' => PageComponent::reference($converter(), 'weekly', ArticleResource::class, $items, $envelope),
        // Nothing identified the item type, so there is no name to derive.
        'unidentified item' => PageComponent::reference($converter(), 'length', null, $items, $envelope),
        // The item did not become a component, so the envelope's bytes would be a function of how well
        // it converted rather than of its identity.
        'inline item schema' => PageComponent::reference($converter(), 'length', ArticleResource::class, ['type' => 'object'], $envelope),
        // A `$ref` with a sibling is not a pointer at a component either.
        'item ref with siblings' => PageComponent::reference($converter(), 'length', ArticleResource::class, $items + ['title' => 'Article'], $envelope),
        // Turned off.
        default => PageComponent::reference($converter(hoist: false), 'length', ArticleResource::class, $items, $envelope),
    };

    expect($reference)->toBeNull();
})->with(['unknown kind', 'unidentified item', 'inline item schema', 'item ref with siblings', 'hoisting disabled']);

it('describes a page by its paginator kind and nothing else', function (string $kind) use ($items): void {
    // A page has no class behind it, so the sentence is all a reader gets — and it is a fact about the
    // SHAPE, never about what was paginated. Two item types paginated the same way therefore read the
    // same, which is what keeps renaming one resource from rewriting prose on a page of another.
    $articles = PaginationEnvelope::of($kind, $items);
    $authors = PaginationEnvelope::of($kind, ['$ref' => '#/components/schemas/AuthorResource']);

    expect($articles['description'] ?? null)->toBeString()->not->toBe('')
        ->and($authors['description'])->toBe($articles['description'])
        // …and it is not a restatement of a member's: the page is the envelope, not what is inside it.
        ->and($articles['description'])->not->toBe($articles['properties']['meta']['description']);
})->with(['length', 'simple', 'cursor']);

it('builds the shape each producer really has for a kind, and records where it has none', function (string $producer, string $kind, string $built) use ($items, $envelope, $parts): void {
    // The domain is both producers crossed with every kind the terminal table can report, and a pair a
    // builder has no arm for owes a ROW saying so rather than falling between two hand lists. Spatie
    // ships no simple-paginator collectable, so `data simple` is answered with the length-aware
    // envelope — and the SENTENCE follows the kind that was built, never the one that was asked for.
    expect(($producer === 'laravel' ? PaginationEnvelope::builds($kind) : SpatieDataEnvelope::builds($kind)))->toBe($built)
        ->and($envelope($producer, $kind, $items))->toBe($envelope($producer, $built, $items))
        ->and($parts($producer, $kind))->toBe($parts($producer, $built))
        ->and($envelope($producer, $kind, $items)['description'])->toBe(PageComponent::description($built));
})->with([
    'laravel length' => ['laravel', 'length', 'length'],
    'laravel simple' => ['laravel', 'simple', 'simple'],
    'laravel cursor' => ['laravel', 'cursor', 'cursor'],
    'data length' => ['data', 'length', 'length'],
    'data simple' => ['data', 'simple', 'length'],
    'data cursor' => ['data', 'cursor', 'cursor'],
    // An unknown kind is answered with the length-aware shape by both, so it is owed the length-aware
    // sentence: prose describing some other shape is the confident lie a vague answer exists to avoid.
    'laravel unknown' => ['laravel', 'weekly', 'length'],
    'data unknown' => ['data', 'weekly', 'length'],
]);

it('describes a page as counting or cursoring exactly as its own meta does', function (string $producer, string $kind) use ($items, $envelope, $parts): void {
    // What a page SAYS, checked against the shape it sits on rather than against a copy of the string.
    // A page whose meta carries a record total is described as carrying totals and one whose meta does
    // not is described as carrying none; a page whose meta carries cursor tokens says so and one whose
    // does not stays quiet about them. Swapping two sentences in the table, or moving one onto another
    // kind, breaks this — which a distinctness check or a two-builders-agree check does not.
    $said = $envelope($producer, $kind, $items)['description'];
    $meta = $parts($producer, $kind)['meta']['schema']['properties'];

    $claimsTotals = stripos($said, 'totals') !== false && stripos($said, 'no totals') === false;
    $claimsCursors = stripos($said, 'cursor') !== false;

    expect($claimsTotals)->toBe(array_key_exists('total', $meta), $producer.' '.$kind.' misdescribes its totals')
        ->and($claimsCursors)->toBe(array_key_exists('next_cursor', $meta), $producer.' '.$kind.' misdescribes its cursors');
})->with($envelopes);

it('reads a page one way across both builders exactly where they build one shape', function () use ($items, $envelope): void {
    // Two producers build a page of the same kind out of different members, and a page is the same thing
    // either way — so the sentences agree where the built kinds agree, and differ where they differ.
    // Stated as an equivalence, because "both builders say the same" alone is satisfied by one sentence
    // for everything, and "the three read apart" alone is satisfied by the wrong three.
    $kinds = array_values(array_unique(PaginationTerminalVisitor::PAGINATOR_TERMINALS));

    expect($kinds)->toHaveCount(3);

    $said = [];
    foreach (['laravel', 'data'] as $producer) {
        foreach ($kinds as $kind) {
            $built = $producer === 'laravel' ? PaginationEnvelope::builds($kind) : SpatieDataEnvelope::builds($kind);
            $said[$producer.' '.$kind] = [$built, $envelope($producer, $kind, $items)['description']];
        }
    }

    expect($said)->toHaveCount(6);

    foreach ($said as $left => [$leftKind, $leftSaid]) {
        expect($leftSaid)->toBeString()->not->toBe('');

        foreach ($said as $right => [$rightKind, $rightSaid]) {
            expect($leftSaid === $rightSaid)->toBe(
                $leftKind === $rightKind,
                $left.' and '.$right.' build '.($leftKind === $rightKind ? 'one shape and read apart' : 'two shapes and read alike'),
            );
        }
    }
});
