<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Tests\Support\EmittedDocument;
use Docuccino\Core\Tests\Support\OpenApiMetaSchema;

/**
 * The meta-schema oracle over the adapter's recorded documents: what a REAL application's build produces,
 * emitted to every OpenAPI version and read back against the published schema for that version.
 *
 * Core's own fixtures are hand-written and small; these are the whole workbench, so they carry shapes no
 * hand-written fixture reaches — and the first run of this file found one: thirteen operations across
 * seven of these documents recovered no responses, which 3.1 and 3.2 accept and 3.0 requires. The 3.0
 * emitter answers that with a placeholder `default` response now, so nothing here is pinned.
 *
 * Both serialisations, and both halves matter for the same reason: `Yaml::parse()` answers a PHP array
 * for a mapping AND for a sequence, so every YAML assertion that predates {@see EmittedDocument} was
 * blind to the one distinction a YAML writer can get wrong. Nothing here is pinned in either.
 *
 * The subjects load through {@see loadDocument}, so the oracle reads its own inputs the way the product
 * reads a document. An associative decode would hand every `example: {}` in these goldens to the emitter
 * as `[]`, and the oracle would then validate — and agree with itself about — a document that is not the
 * one committed beside it.
 */

/** @return array<string, array{string, string}> */
function adapterMetaSchemaSubjects(): array
{
    $subjects = [];

    foreach (adapterMetaSchemaGoldens() as $golden) {
        foreach (array_keys(OpenApiMetaSchema::SCHEMAS) as $format) {
            $subjects[basename($golden, '.uir.json').' · '.$format] = [$golden, $format];
        }
    }

    return $subjects;
}

/**
 * Every recorded UIR golden, discovered rather than listed, so a document added tomorrow is validated
 * without anyone remembering to name it here.
 *
 * @return list<string>
 */
function adapterMetaSchemaGoldens(): array
{
    $goldens = [];

    foreach (glob(golden('*.uir.json')) ?: [] as $path) {
        $goldens[] = basename($path);
    }

    sort($goldens);

    return $goldens;
}

/** @return array{mixed, mixed} the JSON emission and the YAML emission of one golden, both as graphs */
function adapterMetaSchemaEmissions(string $golden, string $format): array
{
    $document = UirDocument::fromArray(loadDocument(golden($golden)));

    return [
        json_decode(Formats::emit($format, $document, new EmitOptions)->output, flags: JSON_THROW_ON_ERROR),
        EmittedDocument::parseYaml(Formats::emit($format, $document, (new EmitOptions)->withYaml())->output),
    ];
}

it('emits JSON that answers to its own OpenAPI meta-schema', function (string $golden, string $format): void {
    [$json] = adapterMetaSchemaEmissions($golden, $format);

    expect(OpenApiMetaSchema::findings($format, $json))->toBe([]);
})->with(adapterMetaSchemaSubjects());

it('emits YAML that answers to its own OpenAPI meta-schema', function (string $golden, string $format): void {
    [, $yaml] = adapterMetaSchemaEmissions($golden, $format);

    expect(OpenApiMetaSchema::findings($format, $yaml))->toBe([]);
})->with(adapterMetaSchemaSubjects());

/**
 * The meta-schemas leave Schema Objects unconstrained in 3.1 and 3.2, so a corrupted map INSIDE one is
 * invisible to them. This is the assertion that sees it: one document serialised twice must agree at
 * every position on whether it holds a map, a sequence or a scalar.
 */
it('emits YAML and JSON that agree on every map, sequence and scalar', function (string $golden, string $format): void {
    [$json, $yaml] = adapterMetaSchemaEmissions($golden, $format);

    expect(EmittedDocument::differences($json, $yaml))->toBe([]);
})->with(adapterMetaSchemaSubjects());

/**
 * And on member ORDER, which neither the comparison above nor a meta-schema can see — one walks by name
 * and diffs key sets, the other cannot constrain order at all. These are whole recorded applications at
 * all three versions, where the YAML goldens pin order for three documents at one.
 */
it('emits YAML and JSON that agree on the order they write members in', function (string $golden, string $format): void {
    [$json, $yaml] = adapterMetaSchemaEmissions($golden, $format);

    expect(EmittedDocument::orderDifferences($json, $yaml))->toBe([]);
})->with(adapterMetaSchemaSubjects());

/**
 * The oracle reading its own subjects correctly, stated as bytes. Everything above compares an emission
 * with a schema or with its other serialisation, so all of it stays green on a document loaded WRONG —
 * both sides agree, and both are wrong together. This is the assertion that fails instead.
 */
it('re-emits the empty objects its subjects hold, rather than the lists a plain decode makes of them', function (): void {
    $committed = [];
    $reEmitted = [];

    foreach (adapterMetaSchemaGoldens() as $golden) {
        $held = substr_count((string) file_get_contents(golden($golden)), '"example": {}');

        if ($held === 0) {
            continue;
        }

        $committed[$golden] = $held;
        $reEmitted[$golden] = substr_count(
            Formats::emit('openapi-3.2', UirDocument::fromArray(loadDocument(golden($golden))), new EmitOptions)->output,
            '"example": {}',
        );
    }

    // If no committed golden holds one any more, this test is measuring nothing and says so.
    expect($committed)->not->toBeEmpty()
        ->and($reEmitted)->toBe($committed);
});

/**
 * A scan that finds nothing must fail. Each floor below is set from what the tree measures — 13 goldens,
 * 39 subjects, 12,230 positions, 35 empty maps, 1,131 ordered maps — close enough underneath that a real
 * truncation drops through it, far enough that retiring one golden does not.
 *
 * The empty-map count is the one that keeps THIS file honest: these documents hold empty maps, and a
 * reader that stopped preserving them — or an emitter that stopped writing them — would leave every
 * assertion above passing on a document with nothing left to get wrong. Which is why it is the one floor
 * here that was worth nothing at all: pinned at 1 against 35, it passed with 34 of them gone, so the
 * assertion it exists to protect could have been reduced to a single position and still looked green.
 *
 * It counts what was VALIDATED, not what sits on disk: summing the recorded UIR files measured
 * the INPUT, so an emitter answering `{}` for every one of them cleared that floor unchanged —
 * precisely the vacuity a floor exists to catch.
 */
it('validates a plausible minimum of recorded documents, positions and empty maps', function (): void {
    $positions = 0;
    $emptyMaps = 0;
    $orderedMaps = 0;

    foreach (adapterMetaSchemaGoldens() as $golden) {
        [$json, $yaml] = adapterMetaSchemaEmissions($golden, 'openapi-3.2');

        $positions += EmittedDocument::nodes($json) + EmittedDocument::nodes($yaml);
        $emptyMaps += count(EmittedDocument::emptyMaps($json));

        // The floor the order assertion needs: a map with fewer than two members has no order to get
        // wrong, so a subject set that had lost its multi-member maps would satisfy it on nothing.
        $orderedMaps += EmittedDocument::orderedMaps($json);
    }

    expect(count(adapterMetaSchemaGoldens()))->toBeGreaterThanOrEqual(10)
        ->and(count(adapterMetaSchemaSubjects()))->toBeGreaterThanOrEqual(30)
        ->and($positions)->toBeGreaterThanOrEqual(10000)
        ->and($emptyMaps)->toBeGreaterThanOrEqual(25)
        ->and($orderedMaps)->toBeGreaterThanOrEqual(500);
});
