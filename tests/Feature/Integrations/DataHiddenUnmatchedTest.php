<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MappedHiddenData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RenamedHiddenData;

/**
 * The Data mapper's half of `attribute.hidden-unmatched`. A Data class hides by the PROPERTY's name
 * while publishing under whatever `#[MapName]` says, so the two spellings are the thing this reader can
 * get wrong — and getting it wrong publishes the field the author wrote the attribute to keep out.
 *
 * @param  list<string>  $properties
 * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
 */
function dataHiddenComponent(string $fqcn, array $properties): array
{
    $engine = new StubTypeEngine(classes: [
        $fqcn => new ClassMetadata($fqcn, array_map(
            static fn (string $name): PropertyMetadata => new PropertyMetadata($name, ScalarT::string()),
            $properties,
        )),
    ]);

    $components = new ComponentRegistry;
    (new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], $engine, $components))->toSchema(new ClassT($fqcn));

    return [$components->schemas(), $components->diagnostics()];
}

it('reports a Data class deny-list left behind by a rename, with the property still published', function (): void {
    [$schemas, $diagnostics] = dataHiddenComponent(RenamedHiddenData::class, ['id', 'name', 'access_token']);

    expect(diagnosticsCoded($diagnostics, 'attribute.hidden-unmatched'))->toHaveCount(1)
        ->and(diagnosticsCoded($diagnostics, 'attribute.hidden-unmatched')[0]->message)
        ->toContain("#[Hidden('accessToken')]")
        ->toContain('It publishes id, name, access_token.')
        // The point of the report: the field it was written to keep out is in the document beside it.
        ->and($schemas['RenamedHiddenData']['properties'])->toHaveKey('access_token');
});

it('judges the name against the property, not the key #[MapName] publishes it under', function (): void {
    // The deny-list names the property and the schema publishes `token`. Reporting against the published
    // keys would call this working declaration a typo, and the property IS gone — so there is nothing
    // to report and the list in a message must never be the wire keys.
    [$schemas, $diagnostics] = dataHiddenComponent(MappedHiddenData::class, ['id', 'access_token']);

    expect(diagnosticsCoded($diagnostics, 'attribute.hidden-unmatched'))->toBe([])
        ->and(array_keys($schemas['MappedHiddenData']['properties']))->toBe(['id']);
});
