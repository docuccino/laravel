<?php

declare(strict_types=1);

use Docuccino\Laravel\Testing\ApiContract;
use Illuminate\Routing\Router;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Http\Controllers\VersionedEntryController;
use Workbench\App\Http\Middleware\DowngradeToPinnedApiVersion;

/**
 * The verb that earns the contract test its keep.
 *
 * `#[MadeResponseFieldOptional]` makes the older document promise a field is always there, and that
 * promise is only worth anything if the application's own runtime really puts it back for a caller
 * pinned that far. Nothing but a replayed request can tell: the declaration and the runtime are
 * written in different files by different halves of the system, and this is where they are made to
 * agree.
 *
 * It is the opposite case to `#[MadeResponseFieldRequired]`, whose inverse only ever DROPS a `required`
 * entry. That widens what the older document accepts, and a widening of a true statement is true — so
 * no replay can falsify it, and it is safe by construction rather than by being checked.
 *
 * Every request goes through `getJson()`, so the router and the application's middleware really run.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->middleware(DowngradeToPinnedApiVersion::class.':entries')
        ->get('api/versioned-entries', [VersionedEntryController::class, 'index']);

    config()->set('docuccino.documents', versionedEntryDocuments());
});

afterEach(function (): void {
    @unlink(workbenchContractPath('e2026-09-01'));
    @unlink(workbenchContractPath('e2026-06-01'));

    ApiContract::reset();
});

it('publishes the field as optional in the version that stopped guaranteeing it', function (): void {
    $schema = generateDocument(key: 'e2026-09-01')->document->toArray()['components']['schemas']['FormEntryData'];

    expect(array_keys($schema['properties']))->toBe(['id', 'label', 'submittedAt'])
        ->and($schema['required'])->toBe(['id', 'label']);
});

it('publishes the field as guaranteed in a version older than the change', function (): void {
    $schema = generateDocument(key: 'e2026-06-01')->document->toArray()['components']['schemas']['FormEntryData'];

    // `properties` is byte-for-byte what the head publishes; only the promise moved.
    expect(array_keys($schema['properties']))->toBe(['id', 'label', 'submittedAt'])
        ->and($schema['required'])->toBe(['id', 'label', 'submittedAt']);
});

it('serves a response the head version documents when the head version is pinned', function (): void {
    workbenchContract(key: 'e2026-09-01');

    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-09-01')
        ->getJson('api/versioned-entries')
        ->assertOk();

    ApiContract::assertions()->assertValidResponse($response);
    ApiContract::assertions()->assertValidExamples();

    // The unsubmitted entry really omits the key, which is what makes it optional rather than null.
    expect($response->json())->toBe([
        ['id' => 1, 'label' => 'Onboarding', 'submittedAt' => '2026-08-01T09:00:00Z'],
        ['id' => 2, 'label' => 'Offboarding'],
    ]);
});

it('serves a response the older version documents when the older version is pinned', function (): void {
    workbenchContract(key: 'e2026-06-01');

    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-06-01')
        ->getJson('api/versioned-entries')
        ->assertOk();

    // The contract FIRST: it is the assertion that has to catch a runtime whose downgrade stopped
    // working, and a shape check standing in front of it would fail before the contract was consulted.
    ApiContract::assertions()->assertValidResponse($response);
    ApiContract::assertions()->assertValidExamples();

    // And then what the wire carried — the key back on every entry, because the runtime put it there.
    expect($response->json())->toBe([
        ['id' => 1, 'label' => 'Onboarding', 'submittedAt' => '2026-08-01T09:00:00Z'],
        ['id' => 2, 'label' => 'Offboarding', 'submittedAt' => null],
    ]);
});

/*
 * The half that has to be able to FAIL, and the whole reason this verb is worth more than the other
 * two. A response served at the head shape — the key simply absent — is checked against the older
 * version's document, which is exactly what a downgrade that stopped running produces. The assertion
 * refuses it and names the field, so writing the declaration and forgetting the runtime is loud
 * rather than silent.
 */
it('refuses a head-shaped response against the older version, naming the field that is missing', function (): void {
    workbenchContract(key: 'e2026-06-01');

    // Nothing pinned, so the application serves today's shape while the contract is the older version's.
    $response = $this->getJson('api/versioned-entries')->assertOk();

    expect($response->json()[1])->not->toHaveKey('submittedAt');

    try {
        ApiContract::assertions()->assertValidResponse($response);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('GET /api/versioned-entries does not match the documented contract.')
            ->toContain('submittedAt');

        return;
    }

    throw new RuntimeException('The older version accepted a response omitting the field it guarantees.');
});
