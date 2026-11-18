<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\NullableFiltersController;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Spatie's `nullable()` lets a null reach the filter class and says nothing about what the class does with
 * it, and a query string's null is an empty value the framework converted. So the document says what an
 * empty value does only where the filter class is known to match it as null — an exact match on a column —
 * and never because the filter merely lets one through.
 */
it('says what an empty value does only for a filter that matches it as null', function (): void {
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        traceOverrides: [
            NullableFiltersController::class.'::index' => TraceScript::forMethod(
                (string) (new ReflectionClass(NullableFiltersController::class))->getFileName(),
                NullableFiltersController::class,
                'index',
                receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder',
            ),
        ],
    );

    $result = localityBuild(
        static fn (Router $router) => $router->get('api/zz-nullable-filters', [NullableFiltersController::class, 'index']),
        $engine,
    );

    $descriptions = [];
    foreach ($result->document->toArray()['paths']['/api/zz-nullable-filters']['get']['parameters'] ?? [] as $parameter) {
        $descriptions[$parameter['name']] = $parameter['description'] ?? '';
    }

    $empty = 'An empty value matches rows that have no value.';

    expect($descriptions)->toHaveKeys(['filter[status]', 'filter[unset_status]', 'filter[public_id]', 'filter[archived_since]', 'filter[term]'])
        // `where(column, '=', null)` is `whereNull`, whichever modifier let the null through: `default(null)`
        // makes the filter nullable as surely as `nullable()` does, so an empty value really is matched.
        ->and($descriptions['filter[status]'])->toContain($empty)
        ->and($descriptions['filter[unset_status]'])->toContain($empty)
        // A partial match reads null as '' and matches every row with a value; a custom filter and a callback
        // decide for themselves — this one refuses an empty term outright.
        ->and($descriptions['filter[public_id]'])->not->toContain($empty)
        ->and($descriptions['filter[archived_since]'])->not->toContain($empty)
        ->and($descriptions['filter[term]'])->not->toContain($empty);

    foreach ($descriptions as $description) {
        expect($description)->not->toContain('Accepts `null`');
    }
});
