<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;

/**
 * The shape that ended a released export, end to end through the workbench build.
 *
 * A property typed `array<string, mixed>` puts the EMPTY schema under `additionalProperties`, and in a
 * draft the empty schema is an empty PHP array — indistinguishable from an empty list, which is not a
 * schema at all. An `@example` beside it is what makes the example lint look. Neither half is exotic
 * and no fixture in this repo had the pair, so the lint shipped measured against a corpus missing the
 * one shape it could not read: `ArticleData::$metadata` is that specimen, and it is here so the two
 * halves can never drift apart again.
 */
it('emits the empty schema a free-form map publishes as an object, with the example beside it', function (): void {
    bindStubEngine();

    $document = generateDocument()->document->toArray();

    /** @var array<string, mixed> $metadata */
    $metadata = $document['components']['schemas']['Article']['properties']['metadata'];

    // The DRAFT holds the empty schema as an empty array. That is not a defect — an assembled document
    // is PHP arrays and has no other way to say `{}` — but it is the value anything reading the draft
    // sees, and the reason a check must read the canonical form instead.
    expect($metadata['type'])->toBe('object')
        ->and($metadata['additionalProperties'])->toBe([])
        ->and($metadata['example'])->toBe(['source' => 'syndication', 'wordCount' => 1200]);

    // And the ARTIFACT publishes it as the object it means, which is what the check has to be held to.
    expect((new UirEmitter)->emit(generateDocument()->document))
        ->toContain('"additionalProperties": {}');
});

it('says nothing about a free-form map carrying an example, and does not die trying', function (): void {
    // The whole regression in one assertion: on v0.9.0 this build threw InvalidKeywordException out of
    // `docuccino:export` and wrote no document at all.
    bindStubEngine();

    $codes = array_map(
        static fn (Diagnostic $d): string => $d->code,
        generateDocument()->diagnostics,
    );

    expect($codes)->not->toContain('lint.example-mismatch')
        ->and($codes)->not->toContain('lint.example-uncheckable')
        ->and($codes)->not->toContain('document.transformer-failed')
        // Anti-vacuity: the build really does report lint findings, so the three above are silence
        // rather than a channel that stopped working.
        ->and($codes)->toContain('lint.data-leakage');
});
