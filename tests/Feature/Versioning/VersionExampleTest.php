<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Diagnostics\Diagnostic;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\ExamplesController;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * A version document's examples say what its own schemas say.
 *
 * An example is baked into the operation before the version transformer runs, so an older version used
 * to publish today's field name beside a schema declaring the older one — a document contradicting
 * itself, in the one member a consumer copies verbatim. The rename now walks the examples with it, and
 * drops the ones it cannot rewrite with certainty rather than publishing a shape the schema rejects.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
    $router->get('api/versioned-forms/named', [VersionedFormController::class, 'named']);

    $documents = versionedFormDocuments();
    foreach (array_keys($documents) as $key) {
        $documents[$key]['routes']['include'] = ['api/versioned-forms*'];
    }

    config()->set('docuccino.documents', $documents);

    bindStubEngine();
});

it('publishes an example carrying the field name its own schema declares', function (): void {
    $head = generateDocument(key: 'v2026-09-01')->document->toArray();
    $older = generateDocument(key: 'v2026-06-01')->document->toArray();

    expect($head['components']['schemas']['FormData']['properties'])->toHaveKey('title')
        ->and(versionedFormMedia($head)['example'])->toBe([
            ['id' => 1, 'title' => 'Onboarding', 'publishedAt' => '2026-08-01T09:00:00Z'],
        ]);

    expect($older['components']['schemas']['FormData']['properties'])->toHaveKey('name')
        ->and(versionedFormMedia($older)['example'])->toBe([
            ['id' => 1, 'name' => 'Onboarding', 'publishedAt' => '2026-08-01T09:00:00Z'],
        ]);
});

it('rewrites every entry of a named examples map', function (): void {
    $older = generateDocument(key: 'v2026-06-01')->document->toArray();

    expect(versionedFormMedia($older, '/api/versioned-forms/named')['examples'])->toBe([
        'published' => [
            'summary' => 'A form with a publication date',
            'value' => [['id' => 1, 'name' => 'Onboarding', 'publishedAt' => '2026-08-01T09:00:00Z']],
        ],
        'unpublished' => [
            'value' => [['id' => 2, 'name' => 'Offboarding', 'publishedAt' => null]],
        ],
    ]);
});

/*
 * The oracle. `ExampleAudit` holds every published example to the schema beside it — the same check
 * `assertValidExamples()` and the `lint.examples` rule run — and nothing pointed it at a version
 * document until now, which is why the contradiction shipped.
 */
it('publishes no example either version document rejects', function (string $key): void {
    $report = (new ExampleAudit(ContractIndex::fromArray(generateDocument(key: $key)->document->toArray())))->run();

    // A pass over nothing proves nothing: the versioned route publishes examples, so the audit has to
    // have read some.
    expect($report->checked)->toBeGreaterThan(0)
        ->and($report->findings)->toBe([])
        ->and($report->uncheckable)->toBe([]);
})->with(['v2026-09-01', 'v2026-06-01']);

it("reports nothing when the build's own example lint reads a version document", function (string $key): void {
    // The other oracle, on the build rather than on the artifact: `lint.examples` runs LAST, so it sees
    // the document this transformer produced rather than the one the code publishes. Off by default,
    // which is why it did not catch this on its own — a user who turns it on now hears about it.
    config()->set('docuccino.lint.examples', ['enabled' => true, 'allow' => []]);

    $codes = array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code,
        generateDocument(key: $key)->diagnostics,
    );

    expect($codes)->not->toContain('lint.example-mismatch')
        ->and($codes)->not->toContain('lint.example-uncheckable');
})->with(['v2026-09-01', 'v2026-06-01']);

it('drops an example whose shape the schema does not describe, and says so', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms/single', [VersionedFormController::class, 'single']);

    $head = generateDocument(key: 'v2026-09-01');
    $older = generateDocument(key: 'v2026-06-01');

    // The head version publishes it exactly as written — nothing about it is this transformer's doing.
    expect(versionedFormMedia($head->document->toArray(), '/api/versioned-forms/single'))
        ->toHaveKey('example');

    // The older one cannot say where the renamed object stands in a value the schema calls a list, so
    // it publishes none rather than one carrying today's field name.
    expect(versionedFormMedia($older->document->toArray(), '/api/versioned-forms/single'))
        ->not->toHaveKey('example');

    $dropped = array_values(array_filter(
        $older->diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'versioning.example-dropped',
    ));

    expect($dropped)->toHaveCount(1)
        ->and($dropped[0]->severity->value)->toBe('warning')
        ->and($dropped[0]->message)
        ->toContain('/paths/~1api~1versioned-forms~1single/get/responses/200/content/application~1json/example')
        ->toContain('Workbench\App\Api\Versions\FormTitleReplacesName')
        ->and($head->diagnostics)->not->toContain($dropped[0]);
});

it('leaves the examples of a schema the change never names alone', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/widgets', [ExamplesController::class, 'show']);

    config()->set('docuccino.documents.v2026-06-01.routes.include', ['api/versioned-forms*', 'api/widgets']);

    $older = generateDocument(key: 'v2026-06-01')->document->toArray();

    $widget = $older['paths']['/api/widgets']['get']['responses']['200']['content']['application/json']['examples'] ?? [];

    expect($widget['stocked']['value'])->toBe(['id' => 1, 'name' => 'Sprocket', 'status' => 'published']);
});
