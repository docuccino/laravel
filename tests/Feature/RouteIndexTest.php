<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Docuccino\Laravel\Routing\ResolvedRouteIndex;

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
