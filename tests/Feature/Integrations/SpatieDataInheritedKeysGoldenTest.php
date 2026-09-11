<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\InheritedKeysController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\InheritedKeysData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MappedDescendantData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * The keys a Data hierarchy publishes, locked in emitted bytes. The golden corpus otherwise carries no
 * laravel-data class with a parent, so nothing in it could ever move when the name-mapping read
 * changes — and a change to what a tier publishes that moves no golden has not been shown safe.
 *
 * Two routes because the attribute and the property can sit at either end: one class holds the mapper
 * and inherits the property, the other holds the property and inherits the mapper. Both send
 * `display_name`, which the oracle in SpatieDataAdvancedTest pins against the running package.
 */
it('emits the keys of a mapped Data hierarchy byte-identical to its committed golden', function (): void {
    bootLaravelData(null);

    $location = new SourceLocation('');
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            InheritedKeysData::class => new ClassMetadata(InheritedKeysData::class, [
                new PropertyMetadata('displayName', ScalarT::string()),
            ]),
            MappedDescendantData::class => new ClassMetadata(MappedDescendantData::class, [
                new PropertyMetadata('displayName', ScalarT::string()),
            ]),
        ],
        analysisOverrides: [
            InheritedKeysController::class.'::inheritedProperty' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT(InheritedKeysData::class), $location)],
            ),
            InheritedKeysController::class.'::inheritedMapper' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT(MappedDescendantData::class), $location)],
            ),
        ],
    );

    $result = localityBuild(
        static function (Router $router): void {
            $router->get('api/zz-inherited-property', [InheritedKeysController::class, 'inheritedProperty']);
            $router->get('api/zz-inherited-mapper', [InheritedKeysController::class, 'inheritedMapper']);
        },
        $engine,
    );

    $emitted = (new UirEmitter)->emit($result->document);

    assertGolden('spatie-inherited-keys.uir.json', $emitted);

    // What the golden is for, said out loud: a client generated from this document sends and reads
    // `display_name`, which is the key the server actually accepts. `displayName` would be a contract
    // that lies, and it is what either single-class reading of the attribute publishes.
    $schemas = $result->document->toArray()['components']['schemas'];

    expect(array_keys($schemas['InheritedKeysData']['properties']))->toBe(['display_name'])
        ->and(array_keys($schemas['MappedDescendantData']['properties']))->toBe(['display_name']);
});
