<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;
use Workbench\App\Http\Middleware\DowngradeToPinnedApiVersion;

/**
 * The workbench's hand-rolled version shim at the wire. No document is generated anywhere here: this
 * proves the APPLICATION's half — the runtime Docuccino never reads and never runs — so it stands
 * entirely on its own.
 *
 * The routes are registered here rather than in `TestCase::defineRoutes()` because that route set is
 * enumerated verbatim in six byte-locked goldens. Requests go through `getJson()` so the router and
 * the middleware really run — `contractResponse()` builds a response without ever touching the
 * router, so it would prove nothing about the wire.
 */
beforeEach(function (): void {
    /** @var Router $router */
    $router = app('router');

    $router->middleware(DowngradeToPinnedApiVersion::class)->group(static function (Router $router): void {
        $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
        $router->get('api/versioned-forms/plain', static fn () => response('The title stays a title.'));
        $router->get('api/versioned-forms/rejected', static fn () => new JsonResponse(['title' => 'Kept'], 422));
    });
});

it('publishes the current shape when nothing is pinned', function (): void {
    expect($this->getJson('api/versioned-forms')->assertOk()->json())->toBe([
        ['id' => 1, 'title' => 'Onboarding', 'publishedAt' => '2026-08-01T09:00:00Z'],
        ['id' => 2, 'title' => 'Offboarding', 'publishedAt' => null],
    ]);
});

it('walks every element of the list back to the former field name for a pin before the rename', function (): void {
    $body = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-06-01')
        ->getJson('api/versioned-forms')
        ->assertOk()
        ->json();

    expect($body)->toBe([
        ['id' => 1, 'name' => 'Onboarding', 'publishedAt' => '2026-08-01T09:00:00Z'],
        ['id' => 2, 'name' => 'Offboarding', 'publishedAt' => null],
    ]);
});

it('publishes the current shape at or after the version the rename shipped in', function (string $pinned): void {
    $body = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, $pinned)
        ->getJson('api/versioned-forms')
        ->assertOk()
        ->json();

    expect(array_column($body, 'title'))->toBe(['Onboarding', 'Offboarding'])
        ->and(array_column($body, 'name'))->toBe([]);
})->with([
    'the version the rename shipped in' => '2026-09-01',
    'a version after it' => '2027-03-01',
]);

it('leaves a body that is not JSON alone', function (): void {
    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-06-01')
        ->get('api/versioned-forms/plain')
        ->assertOk();

    expect($response->getContent())->toBe('The title stays a title.');
});

it('leaves an error body alone', function (): void {
    $body = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-06-01')
        ->getJson('api/versioned-forms/rejected')
        ->assertStatus(422)
        ->json();

    expect($body)->toBe(['title' => 'Kept']);
});

/*
 * 204 is asserted against the middleware directly rather than over HTTP: the framework blanks a
 * 204's content in `Response::prepare()`, after the route middleware has run, so a routed request
 * cannot tell a body that was left alone from one that was rewritten and then thrown away.
 */
it('leaves a 204 alone', function (): void {
    $response = (new DowngradeToPinnedApiVersion)->handle(
        Request::create('/api/versioned-forms', 'GET', server: ['HTTP_X_API_VERSION' => '2026-06-01']),
        static fn (): JsonResponse => new JsonResponse(['title' => 'Kept'], 204),
    );

    expect($response->getStatusCode())->toBe(204)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['title' => 'Kept']);
});
