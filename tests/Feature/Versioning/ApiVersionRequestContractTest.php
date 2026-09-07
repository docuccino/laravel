<?php

declare(strict_types=1);

use Docuccino\Laravel\Testing\ApiContract;
use Illuminate\Routing\Router;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Http\Controllers\VersionedFormController;
use Workbench\App\Http\Middleware\DowngradeToPinnedApiVersion;
use Workbench\App\Http\Middleware\UpgradeFromPinnedApiVersion;

/**
 * The check a request rename earns its place with: replay a real request spelling the field the way an
 * older version accepted it, with that version pinned, and require BOTH halves of the exchange to
 * validate against that version's document.
 *
 * A request rename is the costliest half of the vocabulary to get wrong. A response verb can only go
 * wrong by a response coming out misshapen; this one goes wrong by the application REFUSING a request
 * it documents as valid — a client locked out rather than mildly misinformed, and the failure a version
 * history introduces most easily, because the inbound migration is the half nobody looks at.
 *
 * It is also the half the exchange assertions do not catch on their own. The last row pins that rather
 * than leaving it to be discovered, and says which assertion is blind for which reason.
 *
 * Every request goes through `postJson()`, so the router, the middleware and the FormRequest's own
 * validation really run.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));

    /** @var Router $router */
    $router = app('router');
    $router->middleware([UpgradeFromPinnedApiVersion::class])
        ->post('api/versioned-forms', [VersionedFormController::class, 'store']);
    $router->get('api/versioned-search', [VersionedFormController::class, 'search']);

    config()->set('docuccino.documents', versionedRequestDocuments());
});

afterEach(function (): void {
    @unlink(workbenchContractPath('r2026-09-01'));
    @unlink(workbenchContractPath('r2026-06-01'));
    @unlink(workbenchContractPath('d2026-06-01'));

    // All of ApiContract's state is static and memoised for the process.
    ApiContract::reset();
});

it('documents the field the code accepts today in the version the rename shipped in', function (): void {
    bindVersionedRequestEngine();
    $schema = generateDocument(key: 'r2026-09-01')->document->toArray()['components']['schemas']['StoreVersionedFormRequest'];

    expect(array_keys($schema['properties']))->toBe(['title'])
        ->and($schema['required'])->toBe(['title']);
});

it('documents the former field name in a version older than the change', function (): void {
    bindVersionedRequestEngine();
    $schema = generateDocument(key: 'r2026-06-01')->document->toArray()['components']['schemas']['StoreVersionedFormRequest'];

    expect(array_keys($schema['properties']))->toBe(['name'])
        // Load-bearing on the way IN: a `required` still naming today's field marks a body carrying the
        // old spelling invalid, which is a client refused by the document that promised to accept it.
        ->and($schema['required'])->toBe(['name'])
        ->and($schema['properties'])->not->toHaveKey('title');
});

/*
 * The examples half, and the finding it records: `ChangedFieldExamples` already descends through
 * `requestBody.content.*`, so a request rename needed no extension of the rewriter at all — this is the
 * assertion that the walk really reaches that position rather than the rewriter being handed a document
 * it never descends into. An example a consumer copies and posts back has to be the shape the version's
 * own schema accepts, or the document contradicts itself in the one member anybody copies.
 */
it('rewrites the example published beside a renamed request body', function (): void {
    bindVersionedRequestEngine();

    $media = static fn (string $key): array => generateDocument(key: $key)->document->toArray()['paths']['/api/versioned-forms']['post']['requestBody']['content']['application/json'];

    expect($media('r2026-09-01')['example'])->toBe(['title' => 'Onboarding'])
        ->and($media('r2026-06-01')['example'])->toBe(['name' => 'Onboarding']);
});

it('accepts and documents a request written the way the older version accepted it', function (): void {
    workbenchContract(key: 'r2026-06-01', bindEngine: bindVersionedRequestEngine(...));

    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-06-01')
        ->postJson('api/versioned-forms', ['name' => 'Onboarding']);

    // The contract first, and no status assertion in front of it, so that a failure here is the
    // contract's answer rather than a shape check standing in for it. Executed rather than claimed —
    // disabling the upgrade middleware makes the RESPONSE line fail with "responded 422, which the
    // contract does not document (it documents 201)", which is the runtime locking a pinned client out.
    // The request line passes either way, and the last row in this file says why.
    ApiContract::assertions()->assertValidRequest($response);
    ApiContract::assertions()->assertValidResponse($response);
    ApiContract::assertions()->assertValidExamples();

    // And then what the application actually did with the older spelling.
    expect($response->status())->toBe(201)
        ->and($response->json())->toBe(['id' => 3, 'title' => 'Onboarding', 'publishedAt' => null]);
});

it('accepts and documents a request written the way the code accepts it, at the head version', function (): void {
    workbenchContract(key: 'r2026-09-01', bindEngine: bindVersionedRequestEngine(...));

    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-09-01')
        ->postJson('api/versioned-forms', ['title' => 'Onboarding'])
        ->assertCreated();

    ApiContract::assertions()->assertValidRequest($response);
    ApiContract::assertions()->assertValidExamples();
});

/*
 * The other half, and the reason the two above are worth anything: the check has to be able to FAIL. A
 * request sent at the head shape is checked against the older version's document, which is exactly what
 * a client that ignored the version header produces — and the assertion refuses it, naming the field the
 * older version demands.
 */
it('refuses a head-shaped request against the older version, naming the field it demands', function (): void {
    workbenchContract(key: 'r2026-06-01', bindEngine: bindVersionedRequestEngine(...));

    // Nothing pinned, so the application validates today's spelling while the contract is the older
    // version's — and it really was accepted, which is what makes the refusal below about the DOCUMENT.
    $response = $this->postJson('api/versioned-forms', ['title' => 'Onboarding'])->assertCreated();

    try {
        ApiContract::assertions()->assertValidRequest($response);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('POST /api/versioned-forms')
            ->toContain('name');

        return;
    }

    throw new RuntimeException('The older version accepted a request carrying the field it renamed.');
});

/*
 * And the mirror, which catches the mistake an inbound migration actually makes: firing when it should
 * not. A body written the OLD way, checked against the HEAD document, is what a runtime that kept
 * downgrading past its own version produces.
 */
it('refuses an old-shaped request against the head version', function (): void {
    workbenchContract(key: 'r2026-09-01', bindEngine: bindVersionedRequestEngine(...));

    // Pinned to the head, so the upgrade middleware deliberately does not fire; the FormRequest refuses
    // the body, and the contract has to refuse it too rather than passing an unvalidated exchange.
    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-09-01')
        ->postJson('api/versioned-forms', ['name' => 'Onboarding']);

    try {
        ApiContract::assertions()->assertValidRequest($response);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())->toContain('title');

        return;
    }

    throw new RuntimeException('The head version accepted a request spelling the field the way it was renamed FROM.');
});

/*
 * And the limit of the check, pinned rather than left for the next reader to find out: on the SHIPPED
 * `error_responses` setting, a broken inbound migration passes every contract assertion this file makes.
 *
 * Two separate reasons, neither of them a bug in the assertions. `assertValidRequest()` is blind by
 * construction — `CaptureRequestBody` is prepended GLOBALLY while a migration is route middleware, so
 * the body it holds to the document is the one that ARRIVED, and an old-shaped body is precisely what
 * the older version documents as valid. And what makes the rows above go red is `assertValidResponse()`
 * seeing an UNDOCUMENTED 422, which only happens because `versionedRequestDocuments()` says
 * `error_responses => 'none'`; the shipped config file says `'default'`, where
 * `ImplicitResponsesExtension` synthesises a 422 for any operation with a validated body. So the
 * application turns a pinned client away, answers with a status its own document describes, and the
 * check has nothing to object to.
 *
 * What catches it is a status assertion beside the contract ones, which is what the guide now says. This
 * row is the weakness itself, held still: it goes red the day the check learns to catch this, and that
 * is the day to delete it.
 */
it('passes a request the application turned away, where the version documents the 422', function (): void {
    // The shipped setting, beside the two documents that opt out of error responses.
    $documents = versionedRequestDocuments();
    $documents['d2026-06-01'] = [...$documents['r2026-06-01'], 'error_responses' => 'default'];
    config()->set('docuccino.documents', $documents);

    /** @var Router $router */
    $router = app('router');
    // The same route without the upgrade middleware, which is a migration that stopped firing. A route
    // re-registered for one URI replaces the earlier one, and the 422 below is what proves it did.
    $router->post('api/versioned-forms', [VersionedFormController::class, 'store']);

    workbenchContract(key: 'd2026-06-01', bindEngine: bindVersionedRequestEngine(...));

    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-06-01')
        ->postJson('api/versioned-forms', ['name' => 'Onboarding']);

    // A client pinned to the version whose document says `name` is the field, refused outright.
    expect($response->status())->toBe(422)
        // …and the version documents that 422, so the exchange is one the document describes.
        ->and(array_keys(generateDocument(key: 'd2026-06-01')->document->toArray()['paths']['/api/versioned-forms']['post']['responses']))
        ->toBe([201, 422]);

    // Both of these passing IS the assertion. Neither is a mistake; together they are the gap.
    ApiContract::assertions()->assertValidRequest($response);
    ApiContract::assertions()->assertValidResponse($response);
});
