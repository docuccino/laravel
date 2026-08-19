<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Support\ArtifactLocator;

/**
 * Which artifact the contract assertions read. The preference is a function of the formats a document
 * exports, never of the order they were listed in — and it has to agree with what the reader can
 * actually parse, since a document may export the same contract as both JSON and YAML.
 */
function locatorConfig(array $targets): DocumentConfig
{
    return new DocumentConfig(
        key: 'default',
        info: ['title' => 'Invoices', 'version' => '1.0.0'],
        raw: ['export' => ['targets' => $targets]],
    );
}

it('prefers the richest format a document exports, whatever order it was listed in', function (array $targets, string $expected): void {
    expect(ArtifactLocator::preferred(locatorConfig($targets))->path)->toBe($expected);
})->with([
    'uir beats openapi, listed second' => [
        [['format' => 'openapi-3.2', 'path' => 'docs/openapi.json'], ['format' => 'uir', 'path' => 'docs/uir.json']],
        'docs/uir.json',
    ],
    '3.2 beats 3.1' => [
        [['format' => 'openapi-3.1', 'path' => 'docs/v31.json'], ['format' => 'openapi-3.2', 'path' => 'docs/v32.json']],
        'docs/v32.json',
    ],
]);

// A YAML target holds the same document, but the contract is read as JSON. Preferring one over a JSON
// target the document also exports handed the assertions a file they could not parse — and it did so
// on a configuration that worked before the preference existed, i.e. an upgrade broke it.
it('passes over a YAML target for a JSON one the document also exports', function (): void {
    $config = locatorConfig([
        ['format' => 'openapi-3.1', 'path' => 'docs/openapi.json'],
        ['format' => 'openapi-3.2', 'path' => 'docs/openapi.yaml'],
    ]);

    expect(ArtifactLocator::preferred($config)->path)->toBe('docs/openapi.json');
});

it('falls back to the first target when a document exports nothing readable as a contract', function (): void {
    $config = locatorConfig([
        ['format' => 'postman', 'path' => 'docs/collection.json'],
        ['format' => 'openapi-3.2', 'path' => 'docs/openapi.yaml'],
    ]);

    expect(ArtifactLocator::preferred($config)->path)->toBe('docs/collection.json');
});
