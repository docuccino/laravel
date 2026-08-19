<?php

declare(strict_types=1);

use Docuccino\Core\Patch\Layer;
use Docuccino\Core\Provenance\Explain\ExplainedNode;
use Docuccino\Core\Provenance\Explain\FieldContribution;
use Docuccino\Core\Provenance\Explain\FieldTrail;
use Docuccino\Core\Provenance\Source;
use Docuccino\Laravel\Support\ProvenanceReport;
use Docuccino\Laravel\Tests\Support\ProvenanceConsole as Console;

/**
 * The report is most of what this command is, so this pins what a reader can act on: a mark and a
 * written-out rung name that both survive the colour being switched off, a `file:line` kept whole,
 * and a confidence shown only where it is low enough to mean anything.
 */
it('gives every rung one colour, and never lets the colour be the only signal', function (Layer $layer, string $ansi): void {
    $node = new ExplainedNode('operation', '/paths/~1x/get', [
        new FieldTrail('summary', [new FieldContribution('producer', $layer, true, 'value')]),
    ]);

    // The same colour on the line and in the legend, so the palette is learned off one screen…
    expect(Console::body([$node], decorated: true))->toContain("\e[".$ansi.'m'.$layer->label())
        ->and(Console::legend(decorated: true))->toContain("\e[".$ansi.'m'.$layer->label())
        // …and with the colour gone the rung is still named, still marked, still indented.
        ->and(Console::body([$node]))->toContain('    ✓ '.$layer->label())
        ->and(Console::legend())->toContain($layer->label());
})->with([
    'fallback' => [Layer::Fallback, '90'],
    'inference' => [Layer::Inference, '36'],
    'integration' => [Layer::Integration, '94'],
    'docblock' => [Layer::Docblock, '32'],
    'attribute' => [Layer::Attribute, '33'],
    'overlay' => [Layer::Overlay, '35'],
    'config' => [Layer::Config, '91'],
]);

it('names the ladder in order, lowest rung first', function (): void {
    expect(Console::legend())->toContain('fallback › inference › integration › docblock › attribute › overlay › config');
});

it('marks the published value and everything it shadowed', function (): void {
    $node = new ExplainedNode('responses.201', '/paths/~1x/get/responses/201', [
        new FieldTrail('description', [
            new FieldContribution('attribute', Layer::Attribute, true, 'The created invoice.'),
            new FieldContribution('fallback', Layer::Fallback, false, 'OK'),
        ]),
    ]);

    expect(Console::body([$node]))->toContain('✓ attribute   "The created invoice."')
        ->and(Console::body([$node]))->toContain('✗ fallback    "OK"')
        ->and(Console::summary([$node]))->toContain('1 field · 2 contributions · 1 shadowed')
        // Said once, because the trail has nowhere to record where a losing value came from.
        ->and(Console::summary([$node]))->toContain('A shadowed value is recorded by producer only')
        // Nothing in the plain render can steer a terminal it is piped into.
        ->and(Console::body([$node]))->not->toContain("\e[");
});

it('shows a confidence only where it is low enough to act on', function (?float $confidence, bool $shown): void {
    $node = new ExplainedNode('operation', '/paths/~1x/get', [
        new FieldTrail('summary', [new FieldContribution('inference', Layer::Inference, true, 'v', new Source('app/Thing.php', 3), $confidence)]),
    ]);

    expect(str_contains(Console::body([$node]), 'confidence'))->toBe($shown);
})->with([
    'a mapper that fully succeeded, which most do' => [0.9, false],
    'certainty' => [1.0, false],
    'a conversion that fell short' => [0.4, true],
    'nothing reported' => [null, false],
]);

it('keeps file:line whole and drops a symbol that only says the file again', function (?string $symbol, string $expected): void {
    $node = new ExplainedNode('operation', '/paths/~1x/get', [
        new FieldTrail('summary', [new FieldContribution('inference', Layer::Inference, true, 'v', new Source('app/Http/Controllers/InvoiceController.php', 42, $symbol))]),
    ]);

    expect(Console::body([$node]))->toContain('app/Http/Controllers/InvoiceController.php:42'.$expected."\n");
})->with([
    'the action itself' => ['App\Http\Controllers\InvoiceController::store', ''],
    'the same class unqualified' => ['InvoiceController::store', ''],
    'a pseudo-symbol worth naming' => ['implicit:validated-request', ' · implicit:validated-request'],
    'a trait the file does not name' => ['App\Http\Controllers\Concerns\Paginates::page', ' · App\Http\Controllers\Concerns\Paginates::page'],
    'nothing' => [null, ''],
]);

it('tells one story once when a whole node came from one place', function (): void {
    $source = new Source('app/Http/Controllers/InvoiceController.php', 42);
    $node = new ExplainedNode('parameters.query:page', '/paths/~1x/get/parameters/0', [
        new FieldTrail('description', [new FieldContribution('integration:query-builder', Layer::Integration, true, 'Page number.', $source)]),
        new FieldTrail('required', [new FieldContribution('integration:query-builder', Layer::Integration, true, false, $source)]),
    ]);

    expect(substr_count(Console::body([$node]), 'InvoiceController.php:42'))->toBe(1)
        ->and(Console::body([$node]))->toContain('  from integration:query-builder · app/Http/Controllers/InvoiceController.php:42')
        // …and so does the remedy, which is the same for every field of one parameter.
        ->and(substr_count(Console::body([$node]), "#[QueryParameter(name: 'page')]"))->toBe(1);
});

it('keeps every source where a node has more than one story to tell', function (): void {
    $node = new ExplainedNode('operation', '/paths/~1x/get', [
        new FieldTrail('summary', [new FieldContribution('docblock', Layer::Docblock, true, 'v', new Source('app/A.php', 1))]),
        new FieldTrail('tags', [new FieldContribution('attribute', Layer::Attribute, true, ['x'], new Source('app/B.php', 2))]),
    ]);

    expect(Console::body([$node]))->toContain('app/A.php:1')
        ->and(Console::body([$node]))->toContain('app/B.php:2')
        ->and(Console::body([$node]))->not->toContain('  from ');
});

it('shows a value as far as it stays scannable', function (mixed $value, string $expected): void {
    $node = new ExplainedNode('operation', '/paths/~1x/get', [
        new FieldTrail('requestBody', [new FieldContribution('config', Layer::Config, true, $value)]),
    ]);

    expect(Console::body([$node]))->toContain($expected);
})->with([
    'a string, quoted so it reads as one' => ['OK', '"OK"'],
    'a list' => [['a', 'b'], '["a","b"]'],
    'a body far past the budget' => [['description' => str_repeat('long ', 40)], '{"description":"long long long long long long long long…'],
]);

it('names the component a node points at', function (): void {
    $node = new ExplainedNode('responses.404', '/paths/~1x/get/responses/404', [
        new FieldTrail('description', [new FieldContribution('fallback', Layer::Fallback, true, 'Not Found')]),
    ], '#/components/responses/NotFound');

    expect(Console::body([$node]))->toContain('responses.404  → #/components/responses/NotFound');
});

it('renders a value an application wrote without letting it steer the terminal', function (): void {
    $node = new ExplainedNode('operation', '/paths/~1x/get', [
        new FieldTrail('summary', [new FieldContribution('docblock', Layer::Docblock, true, "red \x1B[31m <fg=red>now</>")]),
    ]);

    $decorated = Console::body([$node], decorated: true);

    // json_encode neutralises the escape on the way in, and the formatter escape keeps the markup
    // from being obeyed on the way out — so neither reaches the terminal as an instruction.
    expect($decorated)->toContain('\u001b[31m')
        ->and($decorated)->toContain('<fg=red>now</>')
        ->and($decorated)->not->toContain("\e[31m");
});

it('counts nothing for nothing', function (): void {
    expect((new ProvenanceReport)->lines([]))->toBe([])
        ->and((new ProvenanceReport)->summary([]))->toBe(['<fg=gray>0 fields · 0 contributions · 0 shadowed</>'])
        ->and((new ProvenanceReport)->contested([]))->toBeFalse();
});

/**
 * The ladder is three lines of chrome, so it is printed where something lost and nowhere else — the
 * same "earns its place by where it fires" test a diagnostic has to pass.
 */
it('knows whether the ladder has anything to explain', function (): void {
    $uncontested = new ExplainedNode('operation', '/p', [
        new FieldTrail('summary', [new FieldContribution('docblock', Layer::Docblock, true, 'v')]),
    ]);
    $contested = new ExplainedNode('operation', '/p', [
        new FieldTrail('summary', [
            new FieldContribution('attribute', Layer::Attribute, true, 'won'),
            new FieldContribution('docblock', Layer::Docblock, false, 'lost'),
        ]),
    ]);

    expect((new ProvenanceReport)->contested([$uncontested]))->toBeFalse()
        ->and((new ProvenanceReport)->contested([$contested]))->toBeTrue();
});

/** A field a layer resolved to absent is a decision, and reads as one rather than as a missing value. */
it('says a field was removed rather than leaving it blank', function (): void {
    $node = new ExplainedNode('responses.200.content.application/json.schema', '/p', [
        new FieldTrail('items', [new FieldContribution('integration:api-resources', Layer::Integration, true, null, null, null, removed: true)]),
    ]);

    expect(Console::body([$node]))->toContain('✓ integration (removed by this layer)')
        ->and(Console::field($node, $node->fields[0]))->toContain('(removed by this layer — the field is not in the document)');
});

/**
 * `--field` exists because the elided value is the exact thing the reader came to see. Every value in
 * the stack is printed whole, and the winner's remedy still closes it.
 */
it('prints every value of one field in full', function (): void {
    $body = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Invoice']]]];
    $node = new ExplainedNode('operation', '/p', [
        new FieldTrail('requestBody', [
            new FieldContribution('integration:form-request', Layer::Integration, true, $body, new Source('app/Http/Controllers/InvoiceController.php', 42)),
            new FieldContribution('inference', Layer::Inference, false, ['required' => false]),
        ]),
    ]);

    $rendered = Console::field($node, $node->fields[0]);

    expect($rendered)->toContain('"$ref": "#/components/schemas/Invoice"')
        ->and($rendered)->not->toContain('…')
        ->and($rendered)->toContain('✓ integration')
        ->and($rendered)->toContain('✗ inference')
        ->and($rendered)->toContain('app/Http/Controllers/InvoiceController.php:42')
        ->and($rendered)->toContain("→ set it with #[BodyParameter(name: 'total')]");
});

/** The elision is never the end of the road, so the report says where the rest of a value is. */
it('says how to see a value it had to shorten, and only when it shortened one', function (mixed $value, bool $said): void {
    $node = new ExplainedNode('operation', '/p', [
        new FieldTrail('requestBody', [new FieldContribution('config', Layer::Config, true, $value)]),
    ]);

    expect(str_contains(Console::summary([$node]), '`--field=<name>` prints one in full'))->toBe($said);
})->with([
    'a value that fits' => ['short', false],
    'a value past the budget' => [['description' => str_repeat('long ', 40)], true],
]);

/**
 * A `from` line above a `✗` row would look like it spoke for that row too, and it never can — a
 * shadowed contribution records no source at all. A node where something lost keeps every line beside
 * the stack it belongs to.
 */
it('never hoists a source over a node where something lost', function (): void {
    $source = new Source('app/Http/Controllers/InvoiceController.php', 42);
    $node = new ExplainedNode('responses.201', '/p', [
        new FieldTrail('description', [
            new FieldContribution('attribute', Layer::Attribute, true, 'The invoice.', $source),
            new FieldContribution('fallback', Layer::Fallback, false, 'Created'),
        ]),
        new FieldTrail('headers', [new FieldContribution('attribute', Layer::Attribute, true, ['X-Total' => []], $source)]),
    ]);

    expect(Console::body([$node]))->not->toContain('  from ')
        // Each winner keeps its own, so nothing at node level appears to answer for the loser.
        ->and(substr_count(Console::body([$node]), 'InvoiceController.php:42'))->toBe(2);
});
