<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ApiStatusBaseData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\InheritedStatusController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\InheritedStatusData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * The success status of a POST whose Data class takes its `calculateResponseStatus()` from a base,
 * locked in emitted bytes. Nothing in the rest of the corpus stands in that population — the golden
 * documents carry no laravel-data class with a parent at all — so this is where a change to which
 * declarations count is visible in a published document rather than only in a unit's return value.
 *
 * 202 and not 201 is the whole point: 201 is spatie's vendor default for a POST, and publishing it
 * here would tell every generated client to expect a status the server never sends.
 */
it('emits an inherited success status byte-identical to its committed golden', function (): void {
    bootLaravelData(null);

    $location = new SourceLocation('');
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            InheritedStatusData::class => new ClassMetadata(InheritedStatusData::class, [
                new PropertyMetadata('id', ScalarT::string()),
            ]),
        ],
        analysisOverrides: [
            InheritedStatusController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT(InheritedStatusData::class), $location)],
            ),
            // The base's body, which is where the application wrote the rule.
            ApiStatusBaseData::class.'::calculateResponseStatus' => new ActionAnalysis(
                returns: [new ReturnSite(new LiteralT(202), $location)],
            ),
        ],
    );

    $result = localityBuild(
        static fn (Router $router) => $router->post('api/zz-inherited-status', [InheritedStatusController::class, 'store']),
        $engine,
    );

    assertGolden('spatie-inherited-status.uir.json', (new UirEmitter)->emit($result->document));

    $responses = $result->document->toArray()['paths']['/api/zz-inherited-status']['post']['responses'];

    expect(array_keys($responses))->toBe([202]);
});
