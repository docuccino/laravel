<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\DocuccinoServiceProvider;

/**
 * The generator version is the one member of an emitted document that names the release rather than
 * the application. It must therefore travel honestly (so a bug report names the generator that
 * produced the document) AND stay out of the golden lock (so tagging a release is not a mass
 * regeneration). These pin both halves — {@see withoutGeneratorVersion()} owns the reasoning — and
 * staying out of the lock has a write side as well as a read side: a regeneration carries the version
 * the golden already records, so it re-records content and nothing else.
 */
it('publishes the adapter VERSION as the generator version', function (): void {
    bindStubEngine();

    $document = generateDocument()->document->toArray();

    expect($document['x-docuccino']['generator']['version'] ?? null)->toBe(DocuccinoServiceProvider::VERSION)
        ->and($document['x-docuccino']['generator']['name'] ?? null)->toBe('docuccino/laravel');
});

it('compares a golden past a generator version the golden was not recorded with', function (): void {
    bindStubEngine();

    $emitted = (new UirEmitter)->emit(generateDocument()->document);
    $bumped = withGeneratorVersion($emitted, '99.9.9');

    // The bytes really do differ — and the comparison the goldens run through does not care.
    expect($bumped)->not->toBe($emitted);
    assertGolden('workbench.uir.json', $bumped);
    // Doctored bytes must never reach the regeneration path, which writes what it is given.
})->skip(getenv('DOCUCCINO_UPDATE_GOLDEN') === '1', 'Would write a doctored version into the golden.');

it('normalises the generator version and nothing else', function (string $find, string $replace): void {
    $golden = (string) file_get_contents(golden('workbench.uir.json'));
    $tampered = str_replace($find, $replace, $golden);

    expect($tampered)->not->toBe($golden)
        ->and(withoutGeneratorVersion($tampered))->not->toBe(withoutGeneratorVersion($golden));
})->with([
    // The normalised member's own neighbours...
    'the generator spec version' => ['"specVersion": "1.0.0"', '"specVersion": "9.9.9"'],
    'the generator name' => ['"name": "docuccino/laravel"', '"name": "docuccino/rails"'],
    'the document content hash' => ['"contentHash": "', '"contentHash": "0'],
    // ...and the API's OWN version, which spells the key identically and stays byte-locked.
    'the API version in info' => ['"version": "1.0.0"', '"version": "9.9.9"'],
]);

it('regenerates a golden with the version that golden already records', function (): void {
    bindStubEngine();

    $emitted = (new UirEmitter)->emit(generateDocument()->document);
    $recorded = withGeneratorVersion($emitted, '0.9.1');

    // The same content recorded under an older release. A regeneration must leave the file
    // byte-identical: a version bump on a golden whose content did not move is noise in a diff that
    // has to stay isolated, and the only way to keep it isolated is to restore the file by hand.
    expect($recorded)->not->toBe($emitted)
        ->and(regenerateGolden($emitted, $recorded))->toBe($recorded);
});

it('records the current generator version in a golden that is new', function (): void {
    bindStubEngine();

    $emitted = (new UirEmitter)->emit(generateDocument()->document);

    // Nothing on disk to carry forward, so the version this run produced is the honest one to record —
    // and the reason preserving a version can never be spelled as declining to write one.
    expect(regenerateGolden($emitted, null))->toBe($emitted)
        ->and(generatorVersionOf($emitted))->toBe(DocuccinoServiceProvider::VERSION);
});

it('records the current generator version over a golden that records none it can read', function (): void {
    bindStubEngine();

    $emitted = (new UirEmitter)->emit(generateDocument()->document);

    // A truncated or hand-mangled golden has no version to preserve, so it is treated as a new one.
    // Writing nothing, or refusing, would leave the author committing bytes they believe they have
    // just regenerated; this instead lands in the diff, where they can see the file was re-recorded.
    expect(regenerateGolden($emitted, '{ "x-docuccino": { "generator": { "name"'))->toBe($emitted);
});

it('regenerates the content of a golden the build no longer matches', function (): void {
    bindStubEngine();

    $emitted = (new UirEmitter)->emit(generateDocument()->document);
    $recorded = withGeneratorVersion($emitted, '0.9.1');
    $stale = str_replace('"openapi"', '"x-openapi"', $recorded);

    // Preserving the version is not licence to preserve the file: content still regenerates, and only
    // the version travels from the golden that was there.
    expect($stale)->not->toBe($recorded)
        ->and(regenerateGolden($emitted, $stale))->toBe($recorded);
});
