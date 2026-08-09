<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Tests\Support\QbTraceScript;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Real-path coverage (Phase 5c — spatie/laravel-json-api-paginate): the integration is registered
 * through the container (installed guard), traces the action via the shared trace boundary, and
 * contributes JSON:API pagination parameters end-to-end through the pipeline. A scripted trace over a
 * real `jsonPaginate()` chain drives the real visitor (the descent mechanic is shared with the
 * QB trace, which has real-engine proof). Config recovery + the default-config info diagnostic and
 * the cursor-mode/renamed-parameter branches are exercised against the real container binding.
 */
const JSON_PAGINATE_ACTION = 'Workbench\\App\\Http\\Controllers\\FormController::index';

function jsonPaginateOperation(string $chain): GenerationResult
{
    /** @var Router $router */
    $router = app('router');
    $router->get('api/json-paginate', [FormController::class, 'index']);

    app()->instance(TypeEngine::class, new StubTypeEngine(
        traces: [JSON_PAGINATE_ACTION => QbTraceScript::forChain($chain)],
    ));

    return generateDocument();
}

it('documents page[number]/page[size] for a jsonPaginate() terminal, with default config + an info diagnostic', function (): void {
    config()->set('json-api-paginate', []);

    $result = jsonPaginateOperation('QueryBuilder::for(Form::class)->jsonPaginate()');
    $operation = $result->document->toArray()['paths']['/api/json-paginate']['get'];
    $params = paramsByName($operation);

    expect($params)->toHaveKeys(['page[number]', 'page[size]']);
    expect($params['page[size]']['schema'])->toMatchArray(['type' => 'integer', 'default' => 30, 'maximum' => 30])
        ->and($params['page[number]']['x-docuccino']['provenance'][0]['producer'])->toBe('integration:json-api-paginate');

    $codes = array_map(static fn ($d): string => $d->code, $result->diagnostics);
    expect($codes)->toContain('json-api-paginate.default-config');
});

it('honours recovered cursor config with renamed parameters and emits no default-config diagnostic', function (): void {
    config()->set('json-api-paginate', [
        'use_cursor_pagination' => true,
        'pagination_parameter' => 'page',
        'cursor_parameter' => 'after',
        'size_parameter' => 'limit',
        'default_size' => 20,
        'max_results' => 50,
    ]);

    $result = jsonPaginateOperation('QueryBuilder::for(Form::class)->jsonPaginate()');
    $operation = $result->document->toArray()['paths']['/api/json-paginate']['get'];
    $params = paramsByName($operation);

    expect(array_keys($params))->toContain('page[after]', 'page[limit]')
        ->and($params)->not->toHaveKey('page[number]');
    expect($params['page[limit]']['schema'])->toMatchArray(['default' => 20, 'maximum' => 50]);

    $codes = array_map(static fn ($d): string => $d->code, $result->diagnostics);
    expect($codes)->not->toContain('json-api-paginate.default-config');
});

it('adds no JSON:API pagination parameters to an endpoint that does not call jsonPaginate', function (): void {
    config()->set('json-api-paginate', ['default_size' => 30]);

    $result = jsonPaginateOperation('QueryBuilder::for(Form::class)->paginate()');
    $operation = $result->document->toArray()['paths']['/api/json-paginate']['get'];
    $params = paramsByName($operation);

    expect($params)->not->toHaveKey('page[number]')
        ->and($params)->not->toHaveKey('page[size]');
});
