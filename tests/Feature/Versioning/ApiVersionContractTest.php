<?php

declare(strict_types=1);

use Docuccino\Laravel\Testing\ApiContract;
use Illuminate\Routing\Router;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Http\Controllers\VersionedFormController;
use Workbench\App\Http\Middleware\DowngradeToPinnedApiVersion;

/**
 * The check the whole design rests on: replay a real request with a version pinned, and require the
 * response to validate against THAT version's document.
 *
 * Docuccino compiles the declaration and the application serves the wire, and the two can silently
 * disagree — the declared change alters the schema, the app's transformation alters the body, and
 * nothing but this notices when one of them moves. `docs/design/api-versioning.md` names it as the
 * failure Airflow records in production: the dropped field still appears on the wire.
 *
 * Every request goes through `getJson()`, so the router and the application's middleware really run.
 * `contractResponse()` builds a response without ever touching the router, so it would prove nothing
 * about the wire.
 *
 * The wire is only half of what a version document promises, though. `assertValidExamples()` holds the
 * other half — every example the document publishes, against the schema beside it — because an example
 * is what a consumer copies and sends back, and a version rewriting a schema without its examples
 * publishes a document that contradicts itself.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));

    /** @var Router $router */
    $router = app('router');
    $router->middleware(DowngradeToPinnedApiVersion::class)
        ->get('api/versioned-forms', [VersionedFormController::class, 'index']);

    config()->set('docuccino.documents', versionedFormDocuments());
});

afterEach(function (): void {
    @unlink(workbenchContractPath('v2026-09-01'));
    @unlink(workbenchContractPath('v2026-06-01'));

    // All of ApiContract's state is static and memoised for the process.
    ApiContract::reset();
});

it('serves a response the head version documents when the head version is pinned', function (): void {
    workbenchContract(key: 'v2026-09-01');

    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-09-01')
        ->getJson('api/versioned-forms')
        ->assertOk();

    ApiContract::assertions()->assertValidResponse($response);
    ApiContract::assertions()->assertValidExamples();

    expect($response->json())->toBe([
        ['id' => 1, 'title' => 'Onboarding', 'publishedAt' => '2026-08-01T09:00:00Z'],
        ['id' => 2, 'title' => 'Offboarding', 'publishedAt' => null],
    ]);
});

it('serves a response the older version documents when the older version is pinned', function (): void {
    workbenchContract(key: 'v2026-06-01');

    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-06-01')
        ->getJson('api/versioned-forms')
        ->assertOk();

    // The contract FIRST: it is the assertion that has to catch a runtime whose downgrade stopped
    // working, and a shape check standing in front of it would fail before the contract was consulted.
    ApiContract::assertions()->assertValidResponse($response);

    // And the examples the older document publishes, which the rename has to have walked with the
    // schema: one still carrying `title` beside a schema declaring `name` is a document that
    // contradicts itself in the member a consumer copies.
    ApiContract::assertions()->assertValidExamples();

    // And then what the wire actually carried — the old name, because the application's runtime put
    // it back.
    expect($response->json())->toBe([
        ['id' => 1, 'name' => 'Onboarding', 'publishedAt' => '2026-08-01T09:00:00Z'],
        ['id' => 2, 'name' => 'Offboarding', 'publishedAt' => null],
    ]);
});

/*
 * The other half, and the reason the two above are worth anything: the check has to be able to FAIL.
 * A response served at the head shape is checked against the older version's document, which is exactly
 * what a broken downgrade produces — and the assertion refuses it.
 */
it('refuses a head-shaped response against the older version, naming the field that is missing', function (): void {
    workbenchContract(key: 'v2026-06-01');

    // Nothing pinned, so the application serves today's shape while the contract is the older version's.
    $response = $this->getJson('api/versioned-forms')->assertOk();

    expect($response->json()[0])->toHaveKey('title');

    try {
        ApiContract::assertions()->assertValidResponse($response);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('GET /api/versioned-forms does not match the documented contract.')
            ->toContain('name');

        return;
    }

    throw new RuntimeException('The older version accepted a response carrying the field it renamed.');
});

it('reads the document the version names, not the one another test left behind', function (): void {
    // ApiContract memoises its index per process, so a suite asserting against two versions has to be
    // able to move between them — this is the assertion that would silently pass on a stale index.
    workbenchContract(key: 'v2026-06-01');
    expect(ApiContract::documentKey())->toBe('v2026-06-01');

    workbenchContract(key: 'v2026-09-01');

    $response = $this->getJson('api/versioned-forms')->assertOk();

    expect(ApiContract::documentKey())->toBe('v2026-09-01')
        ->and(ApiContract::assertions()->assertValidResponse($response))->toBe($response);
});
