<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Tags\PrefixTagMapper;

/*
 * Tag mapping (design §Multiple documents): `tags.map`/`tags.mapper` rewrite operation tags and
 * `tags.definitions` emit the sorted document-level `tags` array. Uses the shared
 * `stubDocumentArray()` (tests/Pest.php).
 */

it('maps operation tags through the configured map and emits sorted document-level tags', function (): void {
    $document = stubDocumentArray(function (array $raw): array {
        $raw['tags']['map'] = ['Forms' => 'Form Management'];
        $raw['tags']['definitions'] = [
            ['name' => 'Zebra', 'weight' => 10],
            ['name' => 'Form Management', 'description' => 'Manage forms', 'weight' => 1],
        ];

        return $raw;
    });

    // The #[Group('Forms')] operation tag is rewritten.
    expect($document['paths']['/api/forms']['get']['tags'])->toBe(['Form Management']);

    // Document-level tags sort by weight then name, carrying descriptions.
    expect(array_column($document['tags'], 'name'))->toBe(['Form Management', 'Zebra'])
        ->and($document['tags'][0]['description'])->toBe('Manage forms');
});

it('emits the OAS 3.2 tag hierarchy members from the definitions', function (): void {
    $document = stubDocumentArray(function (array $raw): array {
        $raw['tags']['definitions'] = [
            ['name' => 'Invoices', 'parent' => 'Billing', 'kind' => 'nav', 'weight' => 1],
            ['name' => 'Billing', 'summary' => 'Billing', 'kind' => 'nav'],
        ];

        return $raw;
    });

    expect($document['tags'])->toBe([
        ['name' => 'Billing', 'summary' => 'Billing', 'kind' => 'nav'],
        ['name' => 'Invoices', 'parent' => 'Billing', 'kind' => 'nav'],
    ]);
});

it('drops an unresolvable tag parent and reports it, rather than failing the build', function (array $definitions, string $code): void {
    bindStubEngine();
    $result = generateDocument(function (array $raw) use ($definitions): array {
        $raw['tags']['definitions'] = $definitions;

        return $raw;
    });

    foreach ($result->document->toArray()['tags'] as $tag) {
        expect($tag)->not->toHaveKey('parent');
    }

    expect(diagnosticsCoded($result->diagnostics, $code))->toHaveCount(1);
})->with([
    'unknown parent' => [[['name' => 'Invoices', 'parent' => 'Billing']], 'config.unknown-tag-parent'],
    'self parent' => [[['name' => 'Invoices', 'parent' => 'Invoices']], 'config.tag-parent-cycle'],
]);

it('emits byte-identical documents however the tag definitions are ordered', function (): void {
    $definitions = [
        ['name' => 'Refunds', 'parent' => 'Invoices', 'kind' => 'nav', 'weight' => 2],
        ['name' => 'Billing', 'summary' => 'Billing', 'kind' => 'nav'],
        ['name' => 'Invoices', 'parent' => 'Billing', 'weight' => 1],
    ];

    // `x-docuccino.document` goes: configHash digests the raw bag, and reordering a LIST inside it is
    // a genuine config edit. Everything the reader sees must be identical.
    $emit = function (array $definitions): string {
        bindStubEngine();

        $array = generateDocument(function (array $raw) use ($definitions): array {
            $raw['tags']['definitions'] = $definitions;

            return $raw;
        })->document->toArray();
        unset($array['x-docuccino']['document']);

        return (new UirEmitter)->emitArray($array);
    };

    expect($emit(array_reverse($definitions)))->toBe($emit($definitions))
        ->and($emit([$definitions[1], $definitions[2], $definitions[0]]))->toBe($emit($definitions));
});

it('derives a default tag from the controller short name when there is no #[Group]', function (): void {
    // IntegrationsController carries no #[Group]; the default strategy tags its operations by the
    // controller short name with the "Controller" suffix stripped.
    $document = stubDocumentArray(static fn (array $raw): array => $raw);

    expect($document['paths']['/api/article-resources']['get']['tags'])->toBe(['Integrations']);
});

it('runs the default controller tag through tags.map', function (): void {
    $document = stubDocumentArray(function (array $raw): array {
        $raw['tags']['map'] = ['Integrations' => 'Content'];

        return $raw;
    });

    expect($document['paths']['/api/article-resources']['get']['tags'])->toBe(['Content']);
});

it('emits no default tag under the none strategy but keeps explicit #[Group] tags', function (): void {
    $document = stubDocumentArray(function (array $raw): array {
        $raw['tags']['default_strategy'] = 'none';

        return $raw;
    });

    expect($document['paths']['/api/article-resources']['get'])->not->toHaveKey('tags')
        ->and($document['paths']['/api/forms']['get']['tags'])->toBe(['Forms']);
});

it('resolves a custom tags.mapper class-string from the container', function (): void {
    $document = stubDocumentArray(function (array $raw): array {
        $raw['tags']['mapper'] = UppercaseTagMapper::class;

        return $raw;
    });

    expect($document['paths']['/api/forms']['get']['tags'])->toBe(['FORMS']);
});

it('leaves tags untouched and emits no document tags by default', function (): void {
    $document = stubDocumentArray(static fn (array $raw): array => $raw);

    expect($document['paths']['/api/forms']['get']['tags'])->toBe(['Forms'])
        ->and($document)->not->toHaveKey('tags');
});

it('is a PrefixTagMapper by default when a map is set', function (): void {
    $config = app(DocumentConfigFactory::class)->make('default', ['tags' => ['map' => ['a' => 'b']]], 'skeleton');

    expect($config->tagMapper)->toBeInstanceOf(PrefixTagMapper::class);
});

final class UppercaseTagMapper implements TagMapper
{
    public function map(string $tag): string
    {
        return strtoupper($tag);
    }
}
