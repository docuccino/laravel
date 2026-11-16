<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Laravel's route registrar — `Route::middleware(…)`, `name(…)`, `domain(…)` and the rest, chained before
 * the verb — stores an array action's class as `\Foo`, where `Route::get()` stores `Foo`. Both name one
 * class, so the build must read them as one: the same action analysed, under the same source.
 */
it('documents a registrar route as the same action a plain route names', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-forms-plain', [FormController::class, 'index']);
    $router->name('zz-forms.registrar')->get('api/zz-forms-registrar', [FormController::class, 'index']);

    $paths = stubDocumentArray()['paths'];
    $plain = $paths['/api/zz-forms-plain']['get'];
    $registrar = $paths['/api/zz-forms-registrar']['get'];

    $symbols = static fn (array $operation): array => array_values(array_unique(array_filter(array_map(
        static fn (array $record): ?string => $record['source']['symbol'] ?? null,
        $operation['x-docuccino']['provenance'],
    ))));

    // The analyser is asked about the class it knows, so the registrar route publishes the body the
    // action returns rather than going without one.
    expect($registrar['responses']['200']['content']['application/json']['schema'] ?? null)
        ->toBe($plain['responses']['200']['content']['application/json']['schema'])
        ->and($symbols($registrar))->toBe([FormController::class.'::index'])
        ->and($symbols($registrar))->toBe($symbols($plain));
});
