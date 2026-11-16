<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedReferenceController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedReferenceData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedReferenceRangeData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Validation attributes naming another field by reference, at the root and inside nested Data — one object and
 * a list of them — locked in emitted bytes. No golden held one.
 */
function spatieNestedFieldReferencesBuild(): GenerationResult
{
    bootLaravelData(null);

    $int = ScalarT::int();
    $range = new ClassT(NestedReferenceRangeData::class);
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            NestedReferenceData::class => new ClassMetadata(NestedReferenceData::class, [
                new PropertyMetadata('min', $int),
                new PropertyMetadata('max', $int),
                new PropertyMetadata('range', $range),
                new PropertyMetadata('ranges', UnionT::of([new ListT($range), new ClassT(DataClassReflector::OPTIONAL)])),
            ]),
            NestedReferenceRangeData::class => new ClassMetadata(NestedReferenceRangeData::class, [
                new PropertyMetadata('min', $int),
                new PropertyMetadata('max', $int),
                new PropertyMetadata('cap', $int),
            ]),
        ],
        analysisOverrides: [
            NestedReferenceController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))],
            ),
        ],
    );

    return localityBuild(
        static fn (Router $router) => $router->post('api/zz-nested-field-references', [NestedReferenceController::class, 'store']),
        $engine,
    );
}

it('emits field references inside nested Data byte-identical to their committed golden', function (): void {
    assertGolden('spatie-nested-field-references.uir.json', (new UirEmitter)->emit(spatieNestedFieldReferencesBuild()->document));
});

it('names a referenced field where laravel-data compares it', function (): void {
    $document = json_decode((new UirEmitter)->emit(spatieNestedFieldReferencesBuild()->document), true, flags: JSON_THROW_ON_ERROR);
    $body = $document['components']['schemas']['NestedReferenceDataRequest']['properties'];

    // laravel-data resolves `#[GreaterThan('min')]` under the object declaring it, so a nested range compares
    // its own `min`, an item of the list its own, and a reference written from the root the root's field.
    expect($body['max']['description'])->toBe('Must be greater than min.')
        ->and($body['range']['properties']['max']['description'])->toBe('Must be greater than range.min.')
        ->and($body['ranges']['items']['properties']['max']['description'])->toBe('Must be greater than ranges.*.min.')
        ->and($body['range']['properties']['cap']['description'])->toBe('Must be less than max.');
});
