<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Integrations\Support\PageComponent;
use Docuccino\Laravel\Integrations\Support\PaginationEnvelope;
use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;

/**
 * The kind → name table {@see PageComponent} publishes through, and the four things that send an
 * envelope back inline. No mappers are needed: the class asks the context for the policy and hands it
 * a body, so a converter over an empty chain is the whole collaboration.
 */
$converter = static fn (bool $hoist = true): SchemaConverter => new SchemaConverter(
    [],
    new NullTypeEngine,
    new ComponentRegistry,
    new RepresentationPolicy(paginationComponents: $hoist),
);

$items = ['$ref' => '#/components/schemas/ArticleResource'];

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
