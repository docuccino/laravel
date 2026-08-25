<?php

declare(strict_types=1);

use Docuccino\Core\SpecValidation\Validator;

/**
 * The UIR schema read against the adapter's recorded documents: what a REAL application's build
 * produces, checked against the schema that is the long-term product.
 *
 * Core validates hand-written fixtures, which are small and were written to the schema. These are the
 * whole workbench — every integration, every recovery path, the shapes a fixture author would not think
 * to write — and a build that started emitting a member the schema does not define would show up here
 * and nowhere else, since a golden comparison only proves the bytes have not moved.
 */

/**
 * Every recorded UIR golden, discovered rather than listed, so a document added tomorrow is validated
 * without anyone remembering to name it here.
 *
 * @return array<string, array{string}>
 */
function uirSchemaSubjects(): array
{
    $subjects = [];

    foreach (glob(golden('*.uir.json')) ?: [] as $path) {
        $subjects[basename($path, '.uir.json')] = [basename($path)];
    }

    ksort($subjects);

    return $subjects;
}

it('records a document the UIR schema accepts', function (string $golden): void {
    $document = json_decode((string) file_get_contents(golden($golden)), true, flags: JSON_THROW_ON_ERROR);

    $validation = (new Validator)->validate($document);

    expect($validation->errors)->toBe([])
        ->and($validation->isValid())->toBeTrue();
})->with(uirSchemaSubjects());

/**
 * A scan that finds nothing must fail. Well under what the tree holds today, far enough above zero that
 * a glob which stopped matching fails here instead of passing on an empty battery.
 */
it('validates a plausible minimum of recorded documents and described positions', function (): void {
    $paths = 0;
    $schemas = 0;

    foreach (uirSchemaSubjects() as [$golden]) {
        $document = json_decode((string) file_get_contents(golden($golden)), true, flags: JSON_THROW_ON_ERROR);

        $paths += count($document['paths'] ?? []);
        $schemas += count($document['components']['schemas'] ?? []);
    }

    expect(count(uirSchemaSubjects()))->toBeGreaterThanOrEqual(10)
        ->and($paths)->toBeGreaterThanOrEqual(80)
        ->and($schemas)->toBeGreaterThanOrEqual(60);
});
