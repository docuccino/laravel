<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;
use Workbench\App\Http\Middleware\DowngradeToPinnedApiVersion;

/**
 * One route, two documents: the version the code is, and the version before the rename shipped.
 *
 * No golden is committed for either. A live version legitimately changes — a non-breaking correction
 * is backported to every version still being served — so a byte-lock would fail on every honest fix.
 * The facts are pinned instead.
 *
 * The routes and the `documents` bag are declared here rather than in `TestCase::defineRoutes()` and
 * the shipped config: that route set is enumerated verbatim in six byte-locked goldens, and this
 * suite must move none of them.
 */
beforeEach(function (): void {
    // The workbench is not under the testbench skeleton's base path, so the change classes are only
    // reachable once the base path is the adapter package.
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->middleware(DowngradeToPinnedApiVersion::class)
        ->get('api/versioned-forms', [VersionedFormController::class, 'index']);

    config()->set('docuccino.documents', versionedFormDocuments());
});

it('publishes the field the code publishes today in the version the rename shipped in', function (): void {
    $schema = generateDocument(key: 'v2026-09-01')->document->toArray()['components']['schemas']['FormData'];

    expect(array_keys($schema['properties']))->toBe(['id', 'title', 'publishedAt'])
        ->and($schema['required'])->toBe(['id', 'title']);
});

it('publishes the former field name in a version older than the change', function (): void {
    $schema = generateDocument(key: 'v2026-06-01')->document->toArray()['components']['schemas']['FormData'];

    expect(array_keys($schema['properties']))->toBe(['id', 'name', 'publishedAt'])
        ->and($schema['properties']['name'])->toBe(['type' => 'string'])
        ->and($schema['properties'])->not->toHaveKey('title');
});

/*
 * Load-bearing, and the reason the acceptance proof works at all: a `required` still naming today's
 * field would accept a body carrying the new name and reject one carrying the old, which is the exact
 * disagreement the per-version contract test exists to catch.
 */
it('rewrites the required list with the properties it names', function (): void {
    $schema = generateDocument(key: 'v2026-06-01')->document->toArray()['components']['schemas']['FormData'];

    expect($schema['required'])->toBe(['id', 'name'])
        ->and($schema['required'])->not->toContain('title');
});

it('is the version its info.version says, with no second key to disagree with', function (): void {
    // `api_version` declares only THAT the document is a version. Which one it is, is `info.version` —
    // the field OAS already models, so there is nowhere for a second answer to live.
    $head = generateDocument(key: 'v2026-09-01')->document->toArray();
    $older = generateDocument(key: 'v2026-06-01')->document->toArray();

    expect($head['info']['version'])->toBe('2026-09-01')
        ->and($older['info']['version'])->toBe('2026-06-01')
        ->and(versionedFormDocuments()['v2026-09-01']['api_version'])->not->toHaveKey('version');
});

/*
 * A version document that writes no `info.version` states no version at all. Deriving one from nothing
 * would put a version the application does not serve into every operation's enum AND make it the value
 * a client falls back to, so the document is left underived and the build says why.
 */
it('refuses to derive a version from an info.version nobody wrote, and says so', function (): void {
    $result = generateDocument(static function (array $raw): array {
        unset($raw['info']['version']);

        return $raw;
    }, 'v2026-06-01');

    $document = $result->document->toArray();
    $codes = array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $result->diagnostics);

    expect($codes)->toContain('versioning.version-unstated')
        // The factory still publishes the shipped default, which is what a document with nothing to say
        // has always published — but nothing was DERIVED from it.
        ->and($document['info']['version'])->toBe('1.0.0')
        ->and($document['paths']['/api/versioned-forms']['get'])->not->toHaveKey('parameters')
        ->and($document['components']['schemas']['FormData']['properties'])->toHaveKey('title');
});

/*
 * And the counter-case Q2 was: `1.0.0` written on purpose is a version like any other. It is the shipped
 * default too, and nothing can tell the two apart — so an API whose first published version really is
 * `1.0.0`, the likeliest first semver version there is, must not be locked out of the feature.
 */
it('derives a version for a document whose version really is 1.0.0', function (): void {
    config()->set('docuccino.documents', [
        'v1' => [
            'info' => ['title' => 'Forms API', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/versioned-forms']],
            'error_responses' => 'none',
            'versioning' => 'semver',
            'api_version' => ['changes' => ['dir' => 'workbench/app/Api/Versions']],
        ],
    ]);

    $result = generateDocument(key: 'v1');
    $document = $result->document->toArray();
    $codes = array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $result->diagnostics);

    $header = versionHeaderComponent($document);

    expect($codes)->not->toContain('versioning.version-unstated')
        ->and(parameterRefs($document))->toBe(['#/components/parameters/XApiVersion'])
        ->and($header['name'])->toBe('X-Api-Version')
        ->and($header['schema']['enum'])->toBe(['1.0.0'])
        ->and($header['schema']['default'])->toBe('1.0.0');
});

it('leaves a document that states a real version alone about it', function (): void {
    $codes = array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code,
        generateDocument(key: 'v2026-06-01')->diagnostics,
    );

    expect($codes)->not->toContain('versioning.version-unstated');
});

it('declares the version header on every operation, enumerating every configured version', function (string $key, string $version): void {
    $document = generateDocument(key: $key)->document->toArray();
    $header = versionHeaderComponent($document);

    expect(parameterRefs($document))->toBe(['#/components/parameters/XApiVersion'])
        ->and($header['name'])->toBe('X-Api-Version')
        ->and($header['in'])->toBe('header')
        ->and($header['required'])->toBeFalse()
        ->and($header['schema']['enum'])->toBe(['2026-06-01', '2026-09-01'])
        ->and($header['schema']['default'])->toBe($version)
        // A date is not an identifier, so the enum carries the member names that make it one — the same
        // decoration every other published enum gets, spelled for a VERSION rather than for a sort key.
        ->and($header['schema']['x-enum-varnames'])->toBe(['V2026_06_01', 'V2026_09_01'])
        ->and($header['schema']['x-enumNames'])->toBe(['V2026_06_01', 'V2026_09_01'])
        // And the change's own sentence, keyed to the version it shipped in.
        ->and($header['schema']['x-enum-descriptions'])->toBe(['', 'A form publishes `title` where it published `name`.']);
})->with([
    'the head version' => ['v2026-09-01', '2026-09-01'],
    'the older version' => ['v2026-06-01', '2026-06-01'],
]);

/*
 * The enum a semver application publishes, which no date row could have caught: bytewise `1.10.0` sorts
 * BEFORE `1.9.0`, so a byte-sorted enum publishes them backwards — the exact reading the whole of
 * versioning exists to replace, shipped in the artifact a consumer reads. And a version is spelled `V…`
 * rather than falling to the sort-key minting's digit-prefix last resort.
 */
it('orders and names a semver enum as versions rather than as bytes', function (): void {
    config()->set('docuccino.documents', [
        'v1_9' => [
            'info' => ['title' => 'Forms API', 'version' => '1.9.0'],
            'routes' => ['include' => ['api/versioned-forms']],
            'error_responses' => 'none',
            'versioning' => 'semver',
            'api_version' => [],
        ],
        'v1_10' => [
            'info' => ['title' => 'Forms API', 'version' => '1.10.0'],
            'routes' => ['include' => ['api/versioned-forms']],
            'error_responses' => 'none',
            'versioning' => 'semver',
            'api_version' => [],
        ],
    ]);

    $schema = versionHeaderComponent(generateDocument(key: 'v1_9')->document->toArray())['schema'];

    expect($schema['enum'])->toBe(['1.9.0', '1.10.0'])
        ->and($schema['x-enum-varnames'])->toBe(['V1_9_0', 'V1_10_0'])
        ->and($schema['x-enumNames'])->toBe(['V1_9_0', 'V1_10_0']);
});

it('never publishes an enum that leaves out the version the document defaults to', function (): void {
    // A build whose document is not in the `documents` bag — a programmatic one, a key mid-rename —
    // would otherwise publish a `default` its own `enum` refuses, which marks a working request invalid.
    config()->set('docuccino.documents', ['v2026-09-01' => versionedFormDocuments()['v2026-09-01']]);

    $document = generateDocument(static function (array $raw): array {
        $raw['info']['version'] = '2027-03-01';

        return $raw;
    }, 'v2026-09-01')->document->toArray();

    $schema = versionHeaderComponent($document)['schema'];

    expect($schema['enum'])->toBe(['2026-09-01', '2027-03-01'])
        ->and($schema['enum'])->toContain($schema['default']);
});

it('mints the header parameter an identity of its own per operation', function (): void {
    $head = generateDocument(key: 'v2026-09-01')->document->toArray();
    $older = generateDocument(key: 'v2026-06-01')->document->toArray();

    $id = static fn (array $document): string => $document['paths']['/api/versioned-forms']['get']['parameters'][0]['x-docuccino']['id'];

    expect($id($head))->toStartWith('par:v1:')
        // Two documents are two operations, so the parameter is two nodes and not one shared identity.
        ->and($id($older))->not->toBe($id($head));
});

it('names the header the document configures', function (): void {
    $document = generateDocument(static function (array $raw): array {
        $raw['api_version']['header'] = 'Api-Version';

        return $raw;
    }, 'v2026-06-01')->document->toArray();

    expect(versionHeaderComponent($document, 'ApiVersion')['name'])->toBe('Api-Version')
        ->and(parameterRefs($document))->toBe(['#/components/parameters/ApiVersion'])
        ->and($document['components']['parameters'])->not->toHaveKey('XApiVersion');
});

it('leaves an application that documents the header itself to say it its own way', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms/documented', [VersionedFormController::class, 'documented']);

    $document = generateDocument(static function (array $raw): array {
        $raw['routes'] = ['include' => ['api/versioned-forms/documented']];

        return $raw;
    }, 'v2026-06-01')->document->toArray();

    $parameters = $document['paths']['/api/versioned-forms/documented']['get']['parameters'];

    // One parameter, and the author's: two of one name in one location is a document no client can read.
    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]['description'])->toBe('Pin the API version, or take the current one.')
        ->and($parameters[0]['schema'])->not->toHaveKey('enum');
});

it('leaves a document that declares no version untouched', function (): void {
    // The head-document guarantee the six committed goldens depend on: a document with no `api_version`
    // is not an API version, and nothing here moves a byte of it.
    $versioned = generateDocument(key: 'v2026-09-01')->document->toArray();
    $plain = generateDocument(static function (array $raw): array {
        unset($raw['api_version']);

        return $raw;
    }, 'v2026-09-01')->document->toArray();

    expect($plain['info']['version'])->toBe('2026-09-01')
        ->and($plain['paths']['/api/versioned-forms']['get'])->not->toHaveKey('parameters')
        ->and($plain['components']['schemas']['FormData']['properties'])->toHaveKey('title')
        // And the versioned one really did move: a no-op comparison against a no-op proves nothing.
        ->and($versioned['paths']['/api/versioned-forms']['get']['parameters'])->not->toBeEmpty();
});

it('emits a valid document for every version', function (string $key): void {
    $result = generateDocument(key: $key);

    $invalid = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'document.schema-invalid',
    ));

    expect($invalid)->toBe([])
        // Through the real emitter too, so the rewritten node is canonicalised and hashed like any other.
        ->and((new UirEmitter)->emit($result->document))->toContain('"X-Api-Version"');
})->with(['v2026-09-01', 'v2026-06-01']);

it('says nothing about versioning while deriving a version that needs no change', function (): void {
    $codes = array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code,
        generateDocument(key: 'v2026-09-01')->diagnostics,
    );

    expect(array_filter($codes, static fn (string $code): bool => str_starts_with($code, 'versioning.')))->toBe([]);
});
