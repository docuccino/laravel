<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\Pairing;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
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
    // The command keeps node ids where a bare emit drops them: an artifact you commit is one you will
    // later diff, and identities are what make that diff semantic rather than method + path guesswork.
    bindStubEngine();

    $document = generateDocument()->document;

    expect(exportedArtifact([]))
        ->toBe((new OpenApi32Emitter)->emit($document, (new EmitOptions)->withKeepIds()));

    // …and --drop-ids gives back the pure-OpenAPI bytes the emitter produces on its own.
    expect(exportedArtifact(['--drop-ids' => true]))
        ->toBe(file_get_contents(golden('workbench.openapi.json')));
});

/**
 * @param  array<string, mixed>  $options
 */
function exportedArtifact(array $options): string
{
    $out = sys_get_temp_dir().'/docuccino-export-'.uniqid().'.json';

    test()->artisan('docuccino:export', ['--format' => 'openapi-3.2', '--out' => $out] + $options)
        ->assertSuccessful();

    $contents = (string) file_get_contents($out);
    @unlink($out);

    return $contents;
}

/*
 * The default export keeps ids, and the workbench shares its error responses through
 * `components.responses` — so this is the population where an operation's use of a shared response
 * meets the id it carried inline. OpenAPI says a Reference Object "cannot be extended with additional
 * properties" (3.1 and 3.2 allow `summary` and `description`, 3.0 nothing), and the 3.1 meta-schema
 * refuses the whole document over one: the id goes to the nodes that are not references, and the diff
 * still pairs, because it pairs responses by status under an operation it paired by id. A shared
 * parameter is the other half of that population, and ApiVersionHeaderComponentTest holds it.
 *
 * One byte-locked golden, at 3.2. The 3.1 export of this workbench is that document with its two
 * version members respelled — nothing it publishes needs 3.2 — so a second 2000-line golden would lock
 * the same bytes twice. It is held to the 3.2 golden instead, which fails the day the two diverge.
 */
it('exports ids on every node but a reference, and still diffs by identity', function (string $format): void {
    bindStubEngine();

    $out = sys_get_temp_dir().'/docuccino-export-'.uniqid().'.json';
    test()->artisan('docuccino:export', ['--format' => $format, '--out' => $out])->assertSuccessful();
    $artifact = (string) file_get_contents($out);

    try {
        $golden = 'workbench.openapi32.ids.json';

        if ($format === 'openapi-3.2') {
            assertGolden($golden, $artifact);
        } else {
            // Byte for byte, bar the two members that name the version.
            $expected = str_replace(
                ['"openapi": "3.2.0"', '"jsonSchemaDialect": "https://spec.openapis.org/oas/3.2/dialect/base"'],
                ['"openapi": "3.1.1"', '"jsonSchemaDialect": "https://spec.openapis.org/oas/3.1/dialect/base"'],
                (string) file_get_contents(golden($golden)),
                $respelled,
            );

            expect($respelled)->toBe(2)
                ->and($artifact)->toBe($expected);
        }

        $graph = json_decode($artifact, flags: JSON_THROW_ON_ERROR);
        $shared = substr_count($artifact, '"$ref": "#/components/responses/');

        expect($shared)->toBeGreaterThan(0)
            ->and(substr_count($artifact, '"x-docuccino-id": "op:'))->toBeGreaterThan(0)
            ->and(OpenApiMetaSchema::referenceSiblingFindings($format, $graph))->toBe([])
            ->and(OpenApiMetaSchema::findings($format, $graph))->toBe([]);

        $changeset = (new DocumentDiffer)->diff(UirDocument::fromArray(loadDocument($out)), generateDocument()->document);

        expect($changeset->pairing)->toBe(Pairing::Identity)
            ->and($changeset->changes)->toBe([]);
    } finally {
        @unlink($out);
    }
})->with([
    'OpenAPI 3.2' => ['openapi-3.2'],
    'OpenAPI 3.1' => ['openapi-3.1'],
]);

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
