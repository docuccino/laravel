<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\BodylessAttribute\BodylessAttributeController;
use Docuccino\Laravel\Tests\Fixtures\BodylessAttribute\DiscardedBody;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * `DELETE /api/model-widgets/{id}` returns a `JsonResponse<payload, 204>`. Whatever the payload folds
 * to, the documented 204 carries no body: `response()->json(null, 204)` is the idiom that used to emit
 * `{"type": "null"}`, `noContent()` is the path that always worked, and a payload-bearing 204 is code
 * that cannot work as written. Only the action's analysis is re-scripted, so no golden is involved.
 */
it('documents no body under an inferred 204, whatever the payload folded to', function (DType $payload): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make(analysisOverrides: [
        'Workbench\\App\\Http\\Controllers\\IntegrationsController::destroyWidget' => new ActionAnalysis(
            returns: [new ReturnSite(
                new ClassT('Illuminate\\Http\\JsonResponse', [$payload, new LiteralT(204)]),
                new SourceLocation(''),
            )],
        ),
    ]));

    $responses = generateDocument()->document->toArray()['paths']['/api/model-widgets/{id}']['delete']['responses'];

    expect($responses['204']['content'] ?? null)->toBeNull()
        ->and($responses['204']['description'] ?? null)->toBe('No Content');
})->with([
    'response()->json(null, 204)' => [fn (): DType => new NullT],
    'response()->noContent()' => [fn (): DType => new VoidT],
    'response()->json([...], 204)' => [fn (): DType => new ArrayShapeT([new ArrayShapeField('a', ScalarT::int())])],
]);

/**
 * Every producer that converts a payload before writing it has to stop short of the conversion under a
 * bodyless status, not merely let the draft discard the result: converting registers the hoisted
 * component, so a body dropped afterwards leaves it in `components.schemas` with nothing referencing it.
 * A cached fragment carries only the components its operation refs, so the orphan appears on a COLD build
 * and vanishes on a warm one — the same code, different bytes.
 *
 * Each case pairs the 204 with the same body under a 200, which is what makes the negative honest: the
 * component has to be absent because the status dropped it, not because it never resolved.
 */
it('hoists no component schema for an inferred body it is going to drop', function (): void {
    $engine = fn (int $status): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            DiscardedBody::class => new ClassMetadata(DiscardedBody::class, [new PropertyMetadata('id', ScalarT::int())]),
        ],
        analysisOverrides: [
            'Workbench\\App\\Http\\Controllers\\IntegrationsController::destroyWidget' => new ActionAnalysis(
                returns: [new ReturnSite(
                    new ClassT('Illuminate\\Http\\JsonResponse', [new ClassT(DiscardedBody::class), new LiteralT($status)]),
                    new SourceLocation(''),
                )],
            ),
        ],
    );

    app()->instance(TypeEngine::class, $engine(200));
    expect(generateDocument()->document->toArray()['components']['schemas'] ?? [])->toHaveKey('DiscardedBody');

    app()->instance(TypeEngine::class, $engine(204));
    expect(generateDocument()->document->toArray()['components']['schemas'] ?? [])->not->toHaveKey('DiscardedBody');
});

it('hoists no component schema for an attribute-named body it is going to drop', function (string $method, string $verb, bool $hoisted): void {
    app('router')->{$verb}('api/bodyless-attribute', [BodylessAttributeController::class, $method]);
    app()->instance(TypeEngine::class, WorkbenchEngine::make(classOverrides: [
        DiscardedBody::class => new ClassMetadata(DiscardedBody::class, [new PropertyMetadata('id', ScalarT::int())]),
    ]));

    $document = generateDocument()->document->toArray();
    $responses = $document['paths']['/api/bodyless-attribute'][$verb]['responses'];

    expect(array_key_exists('DiscardedBody', $document['components']['schemas'] ?? []))->toBe($hoisted)
        ->and(isset($responses[$hoisted ? '200' : '204']['content']))->toBe($hoisted);
})->with([
    '#[Response(status: 204, type: X)]' => ['destroy', 'delete', false],
    '#[Response(status: 200, type: X)]' => ['show', 'get', true],
]);

it('hoists no component schema for a handler body it is going to drop', function (int $status, bool $hoisted): void {
    // The renderable-exception tier: `render()` folds to a JsonResponse whose status forbids a body.
    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        callables: [
            'Workbench\\App\\Exceptions\\PaymentRequiredException::render' => new ActionAnalysis(
                returns: [new ReturnSite(
                    new ClassT('Illuminate\\Http\\JsonResponse', [new ClassT(DiscardedBody::class), new LiteralT($status)]),
                    new SourceLocation(''),
                )],
            ),
        ],
        classOverrides: [
            DiscardedBody::class => new ClassMetadata(DiscardedBody::class, [new PropertyMetadata('id', ScalarT::int())]),
        ],
    ));

    $document = generateDocument()->document->toArray();

    expect(array_key_exists('DiscardedBody', $document['components']['schemas'] ?? []))->toBe($hoisted);
})->with([
    'render() folding to a 402' => [402, true],
    'render() folding to a 204' => [204, false],
]);

it('warns rather than silently dropping a body an attribute deliberately named under a 204', function (): void {
    // An idiomatic `response()->json(null, 204)` is inference reading code, so it drops in silence; naming
    // a type AND a bodyless status in one attribute is a statement the author wrote and can't be honoured.
    app('router')->delete('api/bodyless-attribute', [BodylessAttributeController::class, 'destroy']);
    app()->instance(TypeEngine::class, WorkbenchEngine::make(classOverrides: [
        DiscardedBody::class => new ClassMetadata(DiscardedBody::class, [new PropertyMetadata('id', ScalarT::int())]),
    ]));

    $codes = array_map(static fn (Diagnostic $d): string => $d->code, generateDocument()->diagnostics);

    expect($codes)->toContain('attribute.body-on-bodyless-status');
});

it('keeps a cold build byte-identical to a warm one when an attribute names a body under a 204', function (): void {
    $dir = sys_get_temp_dir().'/docuccino-bodyless-fragments-'.uniqid('', true);
    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $dir);

    app('router')->delete('api/bodyless-attribute', [BodylessAttributeController::class, 'destroy']);
    app()->instance(TypeEngine::class, WorkbenchEngine::make(classOverrides: [
        DiscardedBody::class => new ClassMetadata(DiscardedBody::class, [new PropertyMetadata('id', ScalarT::int())]),
    ]));

    // Cold builds every fragment; warm restores each with only the components its operation refs. An
    // orphan registered for the dropped body would be in the first bytes and absent from the second.
    $cold = (new UirEmitter)->emit(generateDocument()->document);
    $warm = (new UirEmitter)->emit(generateDocument()->document);

    expect($warm)->toBe($cold)
        ->and($cold)->not->toContain('DiscardedBody');
});

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/docuccino-bodyless-fragments-*') ?: [] as $dir) {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
});

it('still documents a null body under a status that may carry one', function (): void {
    // `response()->json(null, 200)` really does send `null`, so the emptiness is the status's doing and
    // not the payload's — the 200 keeps its `{"type": "null"}` schema.
    app()->instance(TypeEngine::class, WorkbenchEngine::make(analysisOverrides: [
        'Workbench\\App\\Http\\Controllers\\IntegrationsController::destroyWidget' => new ActionAnalysis(
            returns: [new ReturnSite(
                new ClassT('Illuminate\\Http\\JsonResponse', [new NullT, new LiteralT(200)]),
                new SourceLocation(''),
            )],
        ),
    ]));

    $responses = generateDocument()->document->toArray()['paths']['/api/model-widgets/{id}']['delete']['responses'];

    expect($responses['200']['content']['application/json']['schema']['type'] ?? null)->toBe('null')
        ->and($responses)->not->toHaveKey('204');
});
