<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * Which of two versions is older is the product's own question, and versioning asks it the same way the
 * diff policies do. It used to ask `strcmp`, which is right for a fixed-width date and wrong for
 * everything else: bytewise, `1.10.0` comes BEFORE `1.9.0`, so a semver-versioned application would have
 * had its change list applied backwards — silently, because backwards is still deterministic.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
});

/**
 * @return array<string, mixed>
 */
function orderedVersionDocument(string $dir, string $version, ?string $versioning = null): array
{
    return [
        'info' => ['title' => 'Forms API', 'version' => $version],
        'routes' => ['include' => ['api/versioned-forms']],
        'error_responses' => 'none',
        'api_version' => ['changes' => [$dir]],
    ] + ($versioning === null ? [] : ['versioning' => $versioning]);
}

it('undoes a semver chain newest first, which a bytewise order would walk backwards', function (string $version, string $field): void {
    config()->set('docuccino.documents', ['v' => orderedVersionDocument('tests/Fixtures/Versioning/Semver', $version)]);

    $result = generateDocument(key: 'v');
    $schema = $result->document->toArray()['components']['schemas']['FormData'];

    // Bytewise, `1.9.0` sorts after `1.10.0`, so the older change would be applied FIRST: it would look
    // for a `label` nothing had put back yet, raise `versioning.change-target-missing`, and leave the
    // document publishing `label` where it should publish `name`. Silence is half the assertion.
    expect(array_keys($schema['properties']))->toBe(['id', $field, 'publishedAt'])
        ->and($schema['required'])->toBe(['id', $field])
        ->and(array_values(array_filter(
            array_map(static fn (Diagnostic $d): string => $d->code, $result->diagnostics),
            static fn (string $code): bool => str_starts_with($code, 'versioning.'),
        )))->toBe([]);
})->with([
    'before both changes' => ['0.9.0', 'name'],
    'between them' => ['1.9.0', 'label'],
    'at the newer one' => ['1.10.0', 'title'],
]);

it('takes the order the document names over the one its versions look like', function (): void {
    // Read as dates these versions are unreadable, so a document that SAYS `date` gets no order at all —
    // which proves the keyword is what decided, not the shape of the strings.
    config()->set('docuccino.documents', ['v' => orderedVersionDocument('tests/Fixtures/Versioning/Semver', '0.9.0', 'date')]);

    $result = generateDocument(key: 'v');
    $codes = array_map(static fn (Diagnostic $d): string => $d->code, $result->diagnostics);

    expect($codes)->toContain('versioning.unordered')
        ->and(array_keys($result->document->toArray()['components']['schemas']['FormData']['properties']))
        ->toBe(['id', 'title', 'publishedAt']);
});

it('applies nothing when the versions in play are neither all dates nor all semver', function (): void {
    config()->set('docuccino.documents', ['v' => orderedVersionDocument('tests/Fixtures/Versioning/Mixed', '1.2.0')]);

    $result = generateDocument(key: 'v');
    $unordered = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => $d->code === 'versioning.unordered',
    ));

    expect($unordered)->toHaveCount(1)
        ->and($unordered[0]->message)->toContain('neither all dates nor all semver')
        ->and($unordered[0]->help)->toContain('documents.*.versioning')
        // And the document is left at the shape the code publishes rather than half-derived.
        ->and(array_keys($result->document->toArray()['components']['schemas']['FormData']['properties']))
        ->toBe(['id', 'title', 'publishedAt']);
});

it('says nothing about ordering for a document that declares no change at all', function (): void {
    config()->set('docuccino.documents', ['v' => [
        'info' => ['title' => 'Forms API', 'version' => 'whenever'],
        'routes' => ['include' => ['api/versioned-forms']],
        'error_responses' => 'none',
        'api_version' => [],
    ]]);

    // No changes means nothing to order, and a warning about an order nothing needs is noise.
    expect(array_map(static fn (Diagnostic $d): string => $d->code, generateDocument(key: 'v')->diagnostics))
        ->not->toContain('versioning.unordered');
});
