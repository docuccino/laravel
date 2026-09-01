<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Versioning against shapes the REAL analyser recovered, and against version-change classes that live
 * in the fixture application rather than beside the assertions.
 *
 * Every other versioning test hands the transformer a schema somebody wrote — a workbench class the
 * stub engine describes, or a document array built by hand. That proves the transformation and nothing
 * about the two halves it meets in a real application: whether a change written against an application
 * class resolves to the identity the recovery chain wrote, and whether a re-added field can point at a
 * component the chain hoisted rather than at one the test arranged for it.
 *
 * So the shapes here are `App\Data\SnapshotData` and the `App\Data\SnapshotFormData` it holds, read out
 * of the fixture app by Larastan: the members come from `@var`, `@phpstan-var` and a constructor
 * `@param` block, the enum on `SnapshotFormData::$status` comes from a native backed enum two files
 * away, and neither class is loadable in this process — which is the ordinary case for an application
 * class and the one a hand-written twin quietly avoids.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
    loadFixtureAppVersionChanges();

    // The fixture app IS the application here, which is what makes the changes directory an ordinary
    // relative path under it rather than a location arranged for the test.
    app()->setBasePath(FixtureRunner::appRoot());
});

/**
 * One document over the real engine's `SnapshotData`, derived as the API version `$version`.
 *
 * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
 */
function snapshotVersionDocument(string $version): array
{
    $classes = [];
    foreach (['App\\Data\\SnapshotData', 'App\\Data\\SnapshotFormData'] as $fqcn) {
        $classes[$fqcn] = ClassMetadata::fromArray(FixtureRunner::classMetadata($fqcn));
    }

    app()->instance(TypeEngine::class, new StubTypeEngine(
        analyses: ['Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
            returns: [new ReturnSite(new ClassT('App\\Data\\SnapshotData'), new SourceLocation(''))],
        )],
        classes: $classes,
    ));

    config()->set('docuccino.documents', ['v' => [
        'info' => ['title' => 'Snapshots', 'version' => $version],
        'routes' => ['include' => ['api/forms']],
        'error_responses' => 'none',
        'api_version' => ['changes' => ['dir' => 'app/Versioning']],
    ]]);

    $result = generateDocument(key: 'v');

    return [$result->document->toArray(), array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => str_starts_with($diagnostic->code, 'versioning.'),
    ))];
}

it('reads the application\'s own change classes out of the directory it configures', function (): void {
    // The whole loop, in one assertion: two classes under `app/Versioning/`, discovered by scanning,
    // read by reflection, and applied to a document built from analyser output. Silence is the
    // assertion — every way this could half-work reports.
    [, $diagnostics] = snapshotVersionDocument('2026-06-01');

    expect($diagnostics)->toBe([]);
})->group('fixture');

it('moves members the analyser recovered, not members a fixture wrote into a schema', function (): void {
    [$document] = snapshotVersionDocument('2026-06-01');
    $schema = $document['components']['schemas']['SnapshotData'];

    // `candidate` is recovered from its own `@var array<string, mixed>` with the `@example` beside it,
    // and the rename takes the property and everything it carries back to the older spelling.
    expect($schema['properties']['applicant'])->toBe([
        'type' => 'object',
        'additionalProperties' => [],
        'description' => "Inline candidate profile state as it stood at submit: identity, contact details and whatever\nelse the tenant's profile schema carried.",
        'example' => ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
    ])
        ->and($schema['properties'])->not->toHaveKey('candidate');

    // And the required-ness verb over a member that is required because the RECOVERED type says it
    // cannot be null, rather than because a fixture listed it.
    expect($schema['required'])->toBe([
        'snapshot_schema_version',
        'context',
        'applicant',
        'theme_data',
        'forms',
        'attachments',
    ]);
})->group('fixture');

/*
 * The reading the removal verb exists for, against a component the real chain published: the re-added
 * field points at `SnapshotFormData`, which was hoisted because `SnapshotData::$forms` is a
 * `@var list<SnapshotFormData>` the analyser resolved — nothing here named it into existence.
 */
it('points a re-added field at a component the recovery chain hoisted', function (): void {
    [$document] = snapshotVersionDocument('2026-06-01');
    $schemas = $document['components']['schemas'];

    expect($schemas['SnapshotData']['properties']['legacy_form'])->toBe([
        '$ref' => '#/components/schemas/SnapshotFormData',
        'description' => 'The form zone this snapshot was created from.',
    ])
        // Counted against the names already standing, so it lands where the schema says rather than
        // where the verb ran.
        ->and(array_keys($schemas['SnapshotData']['properties']))->toBe([
            'snapshot_schema_version',
            'context',
            'applicant',
            'theme_data',
            'legacy_form',
            'forms',
            'permissions',
            'attachments',
        ]);

    // What the pointer resolves to is the analyser's answer and not a shape any of this could have
    // invented: a native backed enum reached through a property two files away, decorated with the
    // member names a generated client needs.
    expect($schemas['SnapshotFormData']['properties']['status']['enum'])->toBe(['Open', 'Closed', 'Draft'])
        ->and($schemas['SnapshotFormData']['properties']['status']['x-enum-varnames'])->toBe(['Open', 'Closed', 'Draft']);
})->group('fixture');

it('leaves the shape the analyser recovered alone at a version above every change', function (): void {
    [$document, $diagnostics] = snapshotVersionDocument('2027-01-01');
    $properties = $document['components']['schemas']['SnapshotData']['properties'];

    expect($diagnostics)->toBe([])
        ->and($properties)->toHaveKey('candidate')
        ->and($properties)->not->toHaveKey('legacy_form')
        ->and($document['components']['schemas']['SnapshotData']['required'])->toContain('permissions');
})->group('fixture');
