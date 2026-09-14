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
 * rather than the kind that reached it first, what each shape says about itself, and the two things
 * that leave a member where it was. Both producers are read here, because the invariant is across them
 * and not within either.
 */
$converter = static fn (bool $hoist = true): SchemaConverter => new SchemaConverter(
    [],
    new NullTypeEngine,
    new ComponentRegistry,
    new RepresentationPolicy(paginationComponents: $hoist),
);

$items = ['$ref' => '#/components/schemas/ArticleResource'];

/** Producer key → the builder class it stands for. The derived guard below proves there is no third. */
$builders = [
    'laravel' => PaginationEnvelope::class,
    'data' => SpatieDataEnvelope::class,
];

/**
 * Every (producer, kind) pair in the domain — both builders crossed with every kind the terminal table
 * can report, asserted against that cross product below rather than trusted. `data simple` is a ROW and
 * not a gap: spatie ships no simple-paginator collectable, so its builder answers the length-aware
 * envelope there, and a pair nobody states is where a shape and a sentence stop having to agree.
 */
$envelopes = [
    'laravel length' => ['laravel', 'length'],
    'laravel simple' => ['laravel', 'simple'],
    'laravel cursor' => ['laravel', 'cursor'],
    'data length' => ['data', 'length'],
    'data simple' => ['data', 'simple'],
    'data cursor' => ['data', 'cursor'],
];

/**
 * Envelope member → the phrases a sentence stating that member must contain. Keyed by the member names
 * Laravel and spatie actually serialise, so this is a table about the RESPONSE and not a copy of the
 * prose; a member with no row fails, which is the half a list of sentences cannot do for itself.
 */
$named = [
    'active' => ['the page you are on'],
    'current_page' => ['page number'],
    'first' => ['first'],
    'first_page_url' => ['URL', 'first'],
    'from' => ['first and last record'],
    'label' => ['label'],
    'last' => ['last'],
    'last_page' => ['last page'],
    'last_page_url' => ['URL', 'last'],
    'next' => ['next'],
    'next_cursor' => ['cursor', 'next'],
    'next_page_url' => ['URL', 'next'],
    'path' => ['base URL'],
    'per_page' => ['size'],
    'prev' => ['previous'],
    'prev_cursor' => ['cursor', 'previous'],
    'prev_page_url' => ['URL', 'previous'],
    'to' => ['first and last record'],
    'total' => ['record total'],
    'url' => ['URL'],
];

$parts = static fn (string $producer, string $kind): array => $producer === 'laravel'
    ? PaginationEnvelope::parts($kind)
    : SpatieDataEnvelope::parts($kind);

$envelope = static fn (string $producer, string $kind, array $items): array => $producer === 'laravel'
    ? PaginationEnvelope::of($kind, $items)
    : SpatieDataEnvelope::of($kind, $items);

it('exercises every class in the tree that mints an envelope part', function () use ($builders): void {
    // The rows above are hand-maintained on the PRODUCER axis, and the kind axis is the only one read
    // off a source of truth. A third builder — or a fourth, reusing these part names over a shape of its
    // own — would be invisible to every assertion in this file. So the producer set is READ OUT of the
    // source: a class that mints a part and is not exercised here fails.
    $found = [];
    $calls = 0;

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $mints = substr_count($source, 'PaginationParts::part(');
        if ($mints === 0) {
            continue;
        }

        $calls += $mints;
        preg_match('/^namespace\s+([^;]+);/m', $source, $namespace);
        $found[] = $namespace[1].'\\'.$file->getBasename('.php');
    }

    sort($found);
    $exercised = array_values($builders);
    sort($exercised);

    // A scanner that stopped recognising the call would otherwise pass forever; the tree holds eight.
    expect($calls)->toBeGreaterThanOrEqual(6)
        ->and($found)->toBe($exercised);
});

it('crosses both producers with every paginator kind the terminal table can report', function () use ($envelopes, $builders): void {
    // The UNION of the two axes against the domain itself. Guards that divide a domain cover their own
    // subsets and nothing between: the kind axis is read off the terminal table and the producer axis
    // off the source, and a pair belonging to neither list is exactly where `data simple` sat — a kind
    // one builder has no arm for, taking its sentence from a table that does.
    $kinds = array_values(array_unique(PaginationTerminalVisitor::PAGINATOR_TERMINALS));

    expect($kinds)->toHaveCount(3)
        ->and($builders)->toHaveCount(2);

    $domain = [];
    foreach (array_keys($builders) as $producer) {
        foreach ($kinds as $kind) {
            $domain[] = $producer.' '.$kind;
        }
    }

    sort($domain);
    $stated = array_keys($envelopes);
    sort($stated);

    expect($stated)->toBe($domain);
});

it('names every member of a minted part in the sentence that part publishes', function () use ($envelopes, $parts, $named): void {
    // These sentences take the enumerating form — "…: X, Y and Z" — which reads to a consumer as the
    // complete member list, and they cannot check it against the code. So the assertion runs off the
    // SHAPE: every property a part publishes must be named in its sentence, and a property with no
    // phrase in the table fails rather than passing unnoticed. It proves a member is not silently
    // omitted; whether the phrase describes it WELL is prose nothing mechanical can judge.
    $checked = [];

    foreach ($envelopes as [$producer, $kind]) {
        foreach ($parts($producer, $kind) as $part) {
            $sentence = $part['schema']['description'];
            $properties = $part['schema']['properties'] ?? [];

            expect($properties)->toBeArray()->not->toBe([]);

            foreach (array_keys($properties) as $member) {
                expect(array_key_exists($member, $named))
                    ->toBeTrue($part['name'].' publishes '.$member.' and no phrase names it');

                foreach ($named[$member] ?? [] as $phrase) {
                    expect(stripos($sentence, $phrase))
                        ->not->toBeFalse($part['name'].' publishes '.$member.' and never says "'.$phrase.'"');
                }

                $checked[$member] = true;
            }
        }
    }

    // Both directions: a row for a member nothing publishes any more is a table describing a document
    // that has moved on, and a scanner that matched nothing would otherwise pass.
    expect(array_keys($checked))->toHaveCount(count($named))
        ->and(count($checked))->toBeGreaterThanOrEqual(15);
});

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

it('gives every minted member a sentence of its own', function () use ($envelopes, $parts): void {
    // Nothing an application wrote is behind these objects: no class to annotate, no docblock to lift,
    // so what the document says about one is whatever the producer states. Read off the builders rather
    // than listed here, because a member added tomorrow with nothing to say is exactly the defect this
    // covers and a hand list would not notice it.
    $sentences = [];

    foreach ($envelopes as [$producer, $kind]) {
        foreach ($parts($producer, $kind) as $part) {
            expect($part['schema']['description'] ?? null)
                ->toBeString($part['name'].' says nothing about itself')
                ->not->toBe('', $part['name'].' says nothing about itself');

            $sentences[$part['name']][] = $part['schema']['description'];
        }
    }

    // A scanner that stopped seeing the builders would otherwise pass forever.
    expect($sentences)->toHaveCount(8);

    foreach ($sentences as $name => $said) {
        expect(array_unique($said))->toHaveCount(1, $name.' is described more than one way');
    }

    // …and no two of them share a sentence. These components exist BECAUSE their shapes differ — a
    // cursor meta is not a page-counter meta — so prose that fits both describes neither, and a reader
    // choosing between two types on their descriptions would be choosing at random.
    $distinct = array_unique(array_map(static fn (array $said): string => $said[0], $sentences));
    expect($distinct)->toHaveCount(8);
});

it('says what a member is whether the member is hoisted or restated', function (string $producer, string $kind) use ($converter, $items, $parts, $envelope): void {
    // Where a shape is STATED is a placement policy; what it says is not. So the component and the
    // policy-off member carry one sentence, and turning the hoist off never leaves a reader with the
    // same contract described less.
    $context = $converter();
    PaginationParts::hoist($context, $envelope($producer, $kind, $items), $parts($producer, $kind));

    $schemas = $context->components()->schemas();
    $inline = $envelope($producer, $kind, $items);

    foreach ($parts($producer, $kind) as $member => $part) {
        $stated = $part['list'] ? $inline['properties'][$member]['items'] : $inline['properties'][$member];

        expect($stated)->toHaveKey('description')
            ->and($stated)->toBe($schemas[$part['name']]);
    }
})->with($envelopes);

it('refuses a member that is not the shape its part names, sentence included', function (string $case) use ($converter, $items): void {
    // The sentence rides IN the shape, so it is part of what the hoist compares — written out rather
    // than claimed, because a guard asserted and not executed is how the next reader skips writing one.
    // Either way the member has stopped being the shared shape: pointing it at the component would
    // publish prose this operation never yields, or grant it prose it never stated.
    $context = $converter();
    $parts = PaginationEnvelope::parts('length');
    $envelope = PaginationEnvelope::of('length', $items);

    if ($case === 'a sentence one operation reworded') {
        $envelope['properties']['meta']['description'] = 'Counters for this endpoint only.';
    } else {
        unset($envelope['properties']['meta']['description']);
    }

    $hoisted = PaginationParts::hoist($context, $envelope, $parts);

    expect($hoisted['properties']['meta'])->toBe($envelope['properties']['meta'])
        ->and($context->components()->schemas())->not->toHaveKey('PaginationMeta');
})->with(['a sentence one operation reworded', 'a member stated without its sentence']);

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
