<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Support\LateBoundMarker;

/**
 * End-to-end coverage of the Laravel adapter against the workbench app: golden export bytes,
 * late-bound registration, per-route failure isolation, and determinism.
 */
it('exports UIR and OpenAPI byte-identical to the committed goldens', function (): void {
    bindStubEngine();

    $document = generateDocument()->document;

    assertGolden('workbench.uir.json', (new UirEmitter)->emit($document));
    assertGolden('workbench.openapi.json', (new OpenApi32Emitter)->emit($document));
});

it('writes the artifact end-to-end via docuccino:export', function (): void {
    bindStubEngine();

    $out = sys_get_temp_dir().'/docuccino-export-'.uniqid().'.json';

    $this->artisan('docuccino:export', ['--format' => 'openapi-3.2', '--out' => $out])
        ->assertSuccessful();

    expect(file_get_contents($out))->toBe(file_get_contents(golden('workbench.openapi.json')));

    @unlink($out);
});

it('picks up an extension registered AFTER the app has booted (late-binding trap)', function (): void {
    bindStubEngine();

    // The app is fully booted here; a registration made now must still take effect at build time.
    Docuccino::extend(new LateBoundMarker);

    $document = generateDocument()->document;

    expect($document->info['title'] ?? null)->toBe('LATE-BOUND');
});

it('isolates a broken route to a skeleton without failing the build', function (): void {
    bindStubEngine();

    $result = generateDocument();
    $paths = $result->document->paths ?? [];

    // The healthy routes are documented...
    expect($paths)->toHaveKeys(['/api/forms', '/api/forms/{form}', '/api/widgets', '/api/ping']);
    // ...the excluded route is absent...
    expect($paths)->not->toHaveKey('/api/secret');
    // ...and the broken route is present as a skeleton with an error diagnostic.
    expect($paths)->toHaveKey('/api/broken');

    $errors = array_values(array_filter(
        $result->diagnostics,
        static fn ($d): bool => $d->severity === Severity::Error && $d->code === 'route.build-failed',
    ));
    expect($errors)->not->toBeEmpty()
        ->and($errors[0]->routeSignature)->toBe('GET /api/broken');
});

it('produces byte-identical output across two runs (determinism)', function (): void {
    bindStubEngine();

    $first = (new UirEmitter)->emit(generateDocument()->document);
    $second = (new UirEmitter)->emit(generateDocument()->document);

    expect($second)->toBe($first);
});
