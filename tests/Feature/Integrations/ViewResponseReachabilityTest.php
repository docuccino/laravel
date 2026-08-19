<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Support\FrameworkClasses;
use Docuccino\Laravel\Support\HtmlRepresentation;

/**
 * The real-analyser half of the view refusal: proof that `view('…')` is a type the engine actually hands
 * back, and that it hands back the CONCRETE `Illuminate\View\View` even where the action declares the
 * contract — so recognising only the contract would miss every real app.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('recovers a rendered view from an idiomatic action', function (string $method): void {
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/DashboardPageController.php',
        'App\\Http\\Controllers\\DashboardPageController',
        $method,
    ));
    $returnType = $analysis->returns[0]->type ?? null;

    expect($returnType)->toBeInstanceOf(ClassT::class)
        ->and($returnType->fqcn)->toBe('Illuminate\\View\\View')
        ->and($returnType->typeArgs)->toBe([])
        ->and(FrameworkClasses::isView($returnType->fqcn))->toBeTrue();
})->with([
    // Declared `: View` (the contract) — the analyser narrows it to the implementation.
    'declared' => ['index'],
    // No declared return type at all, so the type comes from the `view()` helper alone.
    'inferred' => ['summary'],
])->group('fixture');

it('keeps a really-recovered view out of components and documents it as HTML', function (): void {
    // The end-to-end version, over the type the analyser genuinely produced and the metadata it genuinely
    // reflects. A view keeps its state protected, so reflection finds nothing at all — an unguarded chain
    // hoists a members-less component and references it as a JSON 200 body, which claims both the wrong
    // media type and a shape the markup does not have.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/DashboardPageController.php',
        'App\\Http\\Controllers\\DashboardPageController',
        'index',
    ));
    $returnType = $analysis->returns[0]->type ?? null;
    $fqcn = 'Illuminate\\View\\View';

    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata($fqcn));
    expect($metadata->properties)->toBe([]);

    [$responses, $document, $diagnostics] = documentForReturn($returnType, [$fqcn => $metadata]);

    $schema = $responses['200']['content'][HtmlRepresentation::MEDIA_TYPE]['schema'];
    unset($schema['x-docuccino']);

    expect(array_keys($responses['200']['content']))->toBe([HtmlRepresentation::MEDIA_TYPE])
        ->and($schema)->toBe(['type' => 'string'])
        ->and(typeSchemas($document))->toBe([])
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))
        ->not->toContain('inferred-response.payload-unrecoverable');
})->group('fixture');
