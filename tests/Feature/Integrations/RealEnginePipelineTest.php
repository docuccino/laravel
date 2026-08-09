<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;

/**
 * Real-engine pipeline smoke (design §5 / §8): the actual inference-phpstan engine analyses a
 * `response()->json([...])` controller out-of-process (its Laravel/Larastan can't share the Pest
 * process), and the RECOVERED `JsonResponse<payload, status>` type — not a hand-authored stub — is
 * driven through the full DocumentGenerator so the emitted response schema is asserted to reflect
 * what inference found. Guards the engine↔pipeline seam that stub-only tests cannot.
 *
 * The pipeline now unwraps `JsonResponse<payload[, status]>`: the whole `JsonResponse` type recovered
 * by the engine is fed in, and the emitted 200 response reflects the PAYLOAD shape (not a generic
 * `{type: object}`). This asserts the Phase-4 unwrapping the workbench stub previously stood in for.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('unwraps a real JsonResponse<payload> into the emitted response schema', function (): void {
    // The real engine recovers jsonShape() as JsonResponse<arrayShape{id,name,tags}, 200>.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/SpikeController.php',
        'App\\Http\\Controllers\\SpikeController',
        'jsonShape',
    ));

    $returnType = $analysis->returns[0]->type ?? null;
    expect($returnType)->toBeInstanceOf(ClassT::class)
        ->and($returnType->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($returnType->typeArgs[0] ?? null)->not->toBeNull();

    // Drive the WHOLE JsonResponse type through the full pipeline and assert the unwrapping renders
    // the payload shape under 200 — real inference, no stub payload extraction in the test.
    $engine = new StubTypeEngine(analyses: [
        'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
            returns: [new ReturnSite($returnType, new SourceLocation(''))],
        ),
    ]);
    app()->instance(TypeEngine::class, $engine);

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');
    $document = app(DocumentGenerator::class)->generate($config, $engine)->document->toArray();

    $schema = $document['paths']['/api/forms']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    // The emitted schema mirrors the real-inferred payload: an object with id/name/tags.
    expect($schema)->toBeArray()
        ->and($schema['type'] ?? null)->toBe('object')
        ->and($schema['properties'] ?? [])->toHaveKeys(['id', 'name', 'tags']);
})->group('fixture');
