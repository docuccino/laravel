<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Tags\PrefixTagMapper;
use Docuccino\Laravel\Tests\Fixtures\Tags\StatefulTagMapper;

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

// A document whose config names one tag twice — OAS says each name in the `tags` array MUST be
// unique, so publishing both entries hands a client generator two definitions of one type.
it('publishes one tags entry for a tag the definitions name twice', function (): void {
    $document = stubDocumentArray(function (array $raw): array {
        $raw['tags']['definitions'] = [
            ['name' => 'Forms', 'summary' => 'Forms', 'weight' => 9],
            ['name' => 'Zebra'],
            ['name' => 'Forms', 'description' => 'Manage forms', 'weight' => 1],
        ];

        return $raw;
    });

    // Merged, and positioned at the lowest weight the two entries state — so it sorts after the
    // unweighted Zebra rather than at the 9 the first entry happened to be written with.
    expect($document['tags'])->toBe([
        ['name' => 'Zebra'],
        ['name' => 'Forms', 'summary' => 'Forms', 'description' => 'Manage forms'],
    ]);
});

it('projects one x-tagGroups group for a duplicated root', function (): void {
    $document = stubDocumentArray(function (array $raw): array {
        $raw['tags']['definitions'] = [
            ['name' => 'Forms'],
            ['name' => 'Forms'],
            ['name' => 'Zebra', 'parent' => 'Forms'],
        ];

        return $raw;
    });

    expect($document['x-tagGroups'])->toBe([['name' => 'Forms', 'tags' => ['Forms', 'Zebra']]]);
});

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

it('degrades to unmapped tags, and says so, for a tags.mapper the container cannot produce', function (mixed $configured): void {
    // What the build DOES, against what ConfigDiagnostics says about it. Every one of these used to be
    // silent or worse: a name nothing loads came out of the container as a raw framework exception, and
    // a class that is no mapper was dropped with the diagnostic list byte-identical to a clean build.
    $result = generateDocument(static function (array $raw) use ($configured): array {
        $raw['tags']['mapper'] = $configured;

        return $raw;
    });

    $reported = diagnosticsCoded($result->diagnostics, 'config.tag-mapper-unusable');

    expect($result->document->toArray()['paths']['/api/forms']['get']['tags'])->toBe(['Forms'])
        ->and($reported)->toHaveCount(1)
        ->and($reported[0]->severity)->toBe(Severity::Warning);
})->with([
    'a name nothing loads' => ['Not\\A\\Real\\Mapper'],
    'a class that is no mapper' => [stdClass::class],
    // Implements the contract and takes a string the container has no way to supply.
    'a mapper the container cannot build' => [StatefulTagMapper::class],
    'not a string at all' => [123],
]);

it('does not fall through to tags.map for a tags.mapper it could not produce, whatever the key held', function (mixed $configured): void {
    // `mapper` present means `mapper` decides. Honouring the other key would publish a third taxonomy —
    // neither what the author configured nor what their code wrote — under a line saying the first of
    // those was dropped, which is the one thing the message must not be able to lie about.
    $config = app(DocumentConfigFactory::class)->make('default', [
        'tags' => ['mapper' => $configured, 'map' => ['Forms' => 'Form Management']],
    ], 'skeleton');

    expect($config->tagMapper)->toBeNull();
})->with([
    'a class that is no mapper' => [stdClass::class],
    'not a string at all' => [123],
    'an empty string' => [''],
]);

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
