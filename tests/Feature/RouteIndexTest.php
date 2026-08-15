<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Docuccino\Laravel\Routing\ResolvedRouteIndex;
use Docuccino\Laravel\Routing\RouteContextBuilder;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Admin\ReportController as AdminReportController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\ReportController as ApiReportController;

/**
 * The resolver reflects each route once while filtering and records it in the shared, scoped
 * ResolvedRouteIndex; the context builder reads that back O(1) instead of re-scanning and
 * re-reflecting the route table (S1). This guards the scoped wiring against a silent regression to
 * the old double-reflection / O(n^2) re-location.
 */
it('records each resolved route in the shared index for O(1) reuse by the builder', function (): void {
    $index = app(ResolvedRouteIndex::class);

    // Scoped: the resolver and the context builder receive this same instance within a build.
    expect(app(ResolvedRouteIndex::class))->toBe($index);

    $document = app(DocumentConfigFactory::class)
        ->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    $descriptors = iterator_to_array(app(LaravelRouteResolver::class)->resolve($document), false);

    $forms = null;
    foreach ($descriptors as $descriptor) {
        if ($descriptor->uri === '/api/forms' && $descriptor->primaryMethod() === 'get') {
            $forms = $descriptor;
        }
    }

    expect($forms)->not->toBeNull();

    $entry = $index->get($forms);
    expect($entry)->not->toBeNull()
        ->and($entry['reflected'])->not->toBeNull();
});

it('locates the route for the host the descriptor names when the index missed', function (?string $domain, ?string $expected): void {
    // The degraded path a descriptor from somebody else's RouteResolver takes. A URI and a method name
    // one route only until two hosts share them, so without the host it reflects whichever route the
    // router held first and documents the wrong controller under the right URI.
    app('router')->domain('a.example.com')->get('api/zz-locate', [ApiReportController::class, 'index']);
    app('router')->domain('b.example.com')->get('api/zz-locate', [AdminReportController::class, 'index']);

    $document = app(DocumentConfigFactory::class)
        ->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    $context = app(RouteContextBuilder::class)->build(
        new RouteDescriptor(['GET', 'HEAD'], '/api/zz-locate', domain: $domain),
        $document,
        new NullTypeEngine,
        [],
        [],
        [],
        new ComponentRegistry,
    );

    if ($expected === null) {
        expect($context)->toBeNull();

        return;
    }

    expect($context)->not->toBeNull()
        ->and($context->actionRef->class)->toBe($expected);
})->with([
    'the second host' => ['b.example.com', '\\'.AdminReportController::class],
    'the first host' => ['a.example.com', '\\'.ApiReportController::class],
    // A resolver that reports no host has said nothing to choose by, so it still gets a route rather
    // than a skeleton — degraded, but an answer, and the same one every build.
    'no host reported' => [null, '\\'.ApiReportController::class],
    // …but a descriptor that DOES name a host and finds no route on it degrades to a skeleton. Handing
    // it a sibling bound elsewhere would document that sibling's middleware, bindings and action under
    // a host it does not answer on: a confident, wrong answer where none was available.
    'a host no route carries' => ['nowhere.example.com', null],
]);
