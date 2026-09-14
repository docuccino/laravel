<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\EnumDescription\StageController;
use Docuccino\Laravel\Tests\Fixtures\EnumDescription\StageRequest;
use Docuccino\Laravel\Tests\Fixtures\EnumDescription\UnreadableStage;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * An enum component reached through a validation RULE — the producer that never goes near the type
 * chain — and what a warm build owes it: the description and the refusal a cold one published, bytes and
 * diagnostics both. A diagnostic raised while that component is built is lost on a warm hit unless it
 * travels on the operation fragment, which would leave the warm build quietly more confident than the
 * cold one.
 *
 * It does NOT prove that the enum's declaration KEYS that fragment, and should not be read as doing so.
 * Cold and warm are built from identical sources here, so a key omitting the enum's file replays an
 * answer that is still correct — under-keying only shows when a keyed input MOVES. Deleting the
 * `dependsOn()` in `EnumComponent::description()` leaves every assertion below green; the dependency is
 * guarded in `EnumComponentDescriptionTest`, which reads what asking the enum what it says recorded.
 */
$routes = static function (Router $router): void {
    $router->post('api/zz-stages', [StageController::class, 'store']);
};

// `rules()` walked as written, so `Rule::enum(…)` folds to a descriptor here exactly as the analyser
// folds it — the path that reaches an enum component without any property being typed by one.
$engine = static fn (): TypeEngine => WorkbenchEngine::make(traceOverrides: [
    StageRequest::class.'::rules' => TraceScript::forMethod(
        (string) (new ReflectionClass(StageRequest::class))->getFileName(),
        StageRequest::class,
        'rules',
    ),
]);

it('serves a warm build the enum description and the refusal a cold one publishes', function () use ($routes, $engine): void {
    // Bytes, document graph and diagnostics, both directions — see assertWarmEqualsCold().
    $warm = assertWarmEqualsCold($routes, $routes, $engine);

    // …and equal-to-cold proves nothing unless the shared answer is the right one. OpenAPI holds
    // `description` on any Schema Object, an `enum`-bearing one included, and
    // `SchemaClassAttributes::HONOURED` promises `#[Description]` is read as the schema description of
    // every schema class — an enum is a class, so a rule that hoists one owes both halves below.
    /** @var array<string, array<string, mixed>> $schemas */
    $schemas = $warm->document->toArray()['components']['schemas'];

    expect($schemas['ReviewStage']['description'])->toBe('How far through review a submission has got.')
        ->and($schemas['UnreadableStage'])->not->toHaveKey('description')
        ->and(array_map(
            static fn (Diagnostic $d): string => $d->message,
            diagnosticsCoded($warm->diagnostics, 'attribute.description-unusable'),
        ))->toBe([
            'The #[Description] on '.UnreadableStage::class.' carries both `text:` and `file:`; the description was not documented.',
        ]);
});
