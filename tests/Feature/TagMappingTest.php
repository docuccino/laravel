<?php

declare(strict_types=1);

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
