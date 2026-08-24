<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Integrations\Support\PaginationEnvelope;
use Docuccino\Laravel\Integrations\Support\PaginationParts;
use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;
use Docuccino\Laravel\Integrations\Support\SpatieDataEnvelope;

/**
 * The envelope-member hoist: which component each shape lands on, that the name follows the shape
 * rather than the kind that reached it first, and the two things that leave a member where it was.
 * Both producers are read here, because the invariant is across them and not within either.
 */
$converter = static fn (bool $hoist = true): SchemaConverter => new SchemaConverter(
    [],
    new NullTypeEngine,
    new ComponentRegistry,
    new RepresentationPolicy(paginationComponents: $hoist),
);

$items = ['$ref' => '#/components/schemas/ArticleResource'];

/** Every (producer, kind) pair the two envelope builders publish between them. */
$envelopes = [
    'laravel length' => ['laravel', 'length'],
    'laravel simple' => ['laravel', 'simple'],
    'laravel cursor' => ['laravel', 'cursor'],
    'data length' => ['data', 'length'],
    'data cursor' => ['data', 'cursor'],
];

$parts = static fn (string $producer, string $kind): array => $producer === 'laravel'
    ? PaginationEnvelope::parts($kind)
    : SpatieDataEnvelope::parts($kind);

$envelope = static fn (string $producer, string $kind, array $items): array => $producer === 'laravel'
    ? PaginationEnvelope::of($kind, $items)
    : SpatieDataEnvelope::of($kind, $items);

it('points every envelope member at the component its shape names', function (string $producer, string $kind) use ($converter, $items, $parts, $envelope): void {
    $context = $converter();
    $hoisted = PaginationParts::hoist($context, $envelope($producer, $kind, $items), $parts($producer, $kind));
    $schemas = $context->components()->schemas();

    // `data` is the one member that cannot be shared: OpenAPI has no generics, so the item type stays
    // restated per page. Everything else became a pointer.
    expect($hoisted['properties']['data'])->toBe(['type' => 'array', 'items' => $items]);

    foreach ($parts($producer, $kind) as $member => $part) {
        $pointer = $part['list']
            ? $hoisted['properties'][$member]['items']
            : $hoisted['properties'][$member];

        expect($pointer)->toBe(['$ref' => '#/components/schemas/'.$part['name']])
            // No allOf anywhere: the page is a flat object of `$ref`s.
            ->and($hoisted)->not->toHaveKey('allOf')
            // …and the component holds exactly what the inline form used to state.
            ->and($schemas[$part['name']] ?? null)->toBe($part['schema']);

        if ($part['list']) {
            expect($hoisted['properties'][$member]['type'])->toBe('array');
        }
    }
})->with($envelopes);

it('gives one name to one shape across every kind and producer', function () use ($envelopes, $parts): void {
    // The dataset above proves each row in isolation. This is the invariant BETWEEN them: a name is a
    // function of the shape, so two kinds that build the same member share its component (Laravel's
    // length-aware and cursor pages carry one `links` object) and two that build different members never
    // collide on a name. Read off the builders, so a shape edited on one side and not the other fails.
    $byName = [];
    $byShape = [];

    foreach ($envelopes as [$producer, $kind]) {
        foreach ($parts($producer, $kind) as $part) {
            $shape = json_encode(PaginationParts::inline($part));
            $byName[$part['name']][] = $shape;
            $byShape[(string) $shape][] = $part['name'];
        }
    }

    // A scanner that stopped seeing the builders would otherwise pass forever.
    expect($byName)->toHaveCount(8)
        ->and(count($byShape))->toBe(8);

    foreach ($byName as $name => $shapes) {
        expect(array_unique($shapes))->toHaveCount(1, $name.' names more than one shape');
    }

    foreach ($byShape as $shape => $names) {
        expect(array_unique($names))->toHaveCount(1, 'one shape landed on '.implode(' and ', array_unique($names)));
    }
});

it('shares one links component between the length-aware and cursor pages', function () use ($converter, $items): void {
    $context = $converter();

    // Two kinds, one registry: the shape they agree on is registered once, and the shapes they don't
    // are two components rather than one that lies about the other.
    $length = PaginationParts::hoist($context, PaginationEnvelope::of('length', $items), PaginationEnvelope::parts('length'));
    $cursor = PaginationParts::hoist($context, PaginationEnvelope::of('cursor', $items), PaginationEnvelope::parts('cursor'));

    expect($length['properties']['links'])->toBe($cursor['properties']['links'])
        ->and($length['properties']['meta'])->not->toBe($cursor['properties']['meta'])
        ->and(array_keys($context->components()->schemas()))
        ->toBe(['PaginationLinks', 'PaginationMeta', 'CursorPaginationMeta']);
});

it('covers every paginator kind the terminal table can report', function () use ($converter, $items, $envelope): void {
    // The rows above only prove the kinds they list; this reads the source of truth, so a kind nothing
    // here knows about would leave those endpoints restating the envelope with the suite still green.
    $kinds = array_values(array_unique(PaginationTerminalVisitor::PAGINATOR_TERMINALS));

    expect($kinds)->toHaveCount(3);

    foreach ($kinds as $kind) {
        $hoisted = PaginationParts::hoist($converter(), $envelope('laravel', $kind, $items), PaginationEnvelope::parts($kind));

        expect($hoisted['properties']['links'])->toHaveKey('$ref')
            ->and($hoisted['properties']['meta'])->toHaveKey('$ref');
    }
});

it('leaves a member where it was when it is not the shape its part names', function (string $case) use ($converter, $items): void {
    $context = $converter();
    $parts = PaginationEnvelope::parts('length');
    $envelope = PaginationEnvelope::of('length', $items);

    if ($case === 'a member some operation varied') {
        // A meta that gained a field for one endpoint is not the shared shape, and a `$ref` to it would
        // publish a body this operation never yields. Widen nothing, claim nothing: leave it stated.
        $envelope['properties']['meta']['properties']['requested_at'] = ['type' => 'string'];
    } else {
        // A wrap key that took a member's place: what sits there is the item array, not the member.
        $envelope['properties']['meta'] = ['type' => 'array', 'items' => $items];
    }

    $hoisted = PaginationParts::hoist($context, $envelope, $parts);

    expect($hoisted['properties']['meta'])->toBe($envelope['properties']['meta'])
        ->and($context->components()->schemas())->not->toHaveKey('PaginationMeta')
        // The member that DID match is still hoisted — one odd member never blocks the others.
        ->and($hoisted['properties']['links'])->toBe(['$ref' => '#/components/schemas/PaginationLinks']);
})->with(['a member some operation varied', 'a wrap key over a member']);

it('restates every member inline when hoisting is off', function (string $producer, string $kind) use ($converter, $items, $parts, $envelope): void {
    $context = $converter(hoist: false);
    $inline = $envelope($producer, $kind, $items);

    expect(PaginationParts::hoist($context, $inline, $parts($producer, $kind)))->toBe($inline)
        ->and($context->components()->schemas())->toBe([]);
})->with($envelopes);
