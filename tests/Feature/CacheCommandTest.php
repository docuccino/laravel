<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Runtime\DocumentCache;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * The runtime document cache commands (design §ops parity): docuccino:cache stores the served
 * OpenAPI payload, docuccino:clear removes it.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

it('caches the built document and stores retrievable JSON', function (): void {
    $this->artisan('docuccino:cache')->assertSuccessful();

    $cached = app(DocumentCache::class)->get('default', 'openapi-3.2');

    expect($cached)->toBeString()
        ->and($cached)->toContain('"openapi": "3.2.0"')
        ->and($cached)->toContain('/api/forms');
});

it('serves the same bytes the OpenAPI export writes', function (): void {
    $this->artisan('docuccino:cache')->assertSuccessful();

    expect(app(DocumentCache::class)->get('default', 'openapi-3.2'))
        ->toBe(file_get_contents(dirname(__DIR__).'/Fixtures/golden/workbench.openapi.json'));
});

it('clears a cached document', function (): void {
    $cache = app(DocumentCache::class);
    $this->artisan('docuccino:cache')->assertSuccessful();
    expect($cache->get('default', 'openapi-3.2'))->not->toBeNull();

    $this->artisan('docuccino:clear')->assertSuccessful();

    expect($cache->get('default', 'openapi-3.2'))->toBeNull();
});

it('fails for an unknown document', function (): void {
    $this->artisan('docuccino:cache', ['document' => 'nope'])->assertFailed();
    $this->artisan('docuccino:clear', ['document' => 'nope'])->assertFailed();
});
