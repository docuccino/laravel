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

/**
 * The other half of the same `{}`-versus-`[]` hazard, one keyword over.
 *
 * `@example {}` on a free-form map is the natural example to write, and it was refused: the reader
 * demanded a NON-EMPTY object because it re-decoded associatively to publish, which would have turned
 * `{}` into `[]` beside a `type: object`. So a valid example was dropped and a warning said `{}` "does
 * not read as object". `example` is data — the canonicalizer does not re-derive its shape — so the
 * object-ness has to survive from the reader all the way to the bytes, which is what this pins.
 */
it('publishes an empty object example as an object, and says nothing about it', function (): void {
    bindStubEngine();

    $build = generateDocument();

    /** @var array<string, mixed> $overrides */
    $overrides = $build->document->toArray()['components']['schemas']['Article']['properties']['overrides'];

    // In the draft the example is a stdClass — the one shape an assembled PHP-array document has for
    // saying `{}` — and it is EMPTY, not the `[]` that would read as a list.
    expect($overrides['example'])->toBeInstanceOf(stdClass::class)
        ->and((array) $overrides['example'])->toBe([]);

    // The bytes, which is the only thing a consumer sees. `[]` here is the defect.
    expect((new UirEmitter)->emit($build->document))->toContain('"example": {}');

    // And nothing is reported: the example was published, so there is nothing to say about it. The
    // populated map beside it is the anti-vacuity — both readings work, not just the empty one.
    $untypable = array_values(array_filter(
        $build->diagnostics,
        static fn (Diagnostic $d): bool => $d->code === 'docblock.example-untypable',
    ));

    expect($untypable)->toBe([])
        ->and($build->document->toArray()['components']['schemas']['Article']['properties']['metadata']['example'])
        ->toBe(['source' => 'syndication', 'wordCount' => 1200]);
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
