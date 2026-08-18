<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\DocuccinoServiceProvider;

/**
 * The generator version is the one member of an emitted document that names the release rather than
 * the application. It must therefore travel honestly (so a bug report names the generator that
 * produced the document) AND stay out of the golden lock (so tagging a release is not a mass
 * regeneration). These pin both halves — {@see withoutGeneratorVersion()} owns the reasoning.
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
    $bumped = (string) preg_replace(
        '/("generator"\s*:\s*\{[^{}]*"version"\s*:\s*)"[^"]*"/',
        '${1}"99.9.9"',
        $emitted,
    );

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
