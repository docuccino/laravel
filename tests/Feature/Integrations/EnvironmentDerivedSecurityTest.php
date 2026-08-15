<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Support\MachineDependentValue;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Real-path coverage for the machine-dependent-value reports: the security extensions read the app's
 * ACTUAL `app.url` / `session.cookie` through the pipeline, publish what they find, and say when what
 * they found describes the build machine rather than the deployment.
 *
 * The published value is never withheld — OAS requires a `tokenUrl` on every flow object, and a cookie
 * scheme naming the wrong cookie is unusable — so every row asserts the value is still there beside the
 * warning. Laravel's own `config/app.php` is `env('APP_URL', 'http://localhost')`, so the localhost row
 * is the DEFAULT experience of an app that never set `APP_URL`, not an edge case.
 */
it('publishes the localhost OAuth endpoints an unset APP_URL produces, and warns', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/mdv-passport', [FormController::class, 'index'])->middleware('scope:read');

    // Exactly what Laravel's shipped config/app.php hands back when APP_URL is unset: a perfectly good
    // string. The extension's own literal fallback never fires on this path.
    config()->set('app.url', 'http://localhost');
    bindStubEngine();
    $result = generateDocument();

    $flows = $result->document->toArray()['components']['securitySchemes']['passport']['flows'];
    $reports = diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE);

    expect($flows['authorizationCode']['tokenUrl'])->toBe('http://localhost/oauth/token')
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->severity)->toBe(Severity::Warning)
        ->and($reports[0]->message)->toContain('http://localhost')
        ->and($reports[0]->message)->toContain('app.url')
        ->and($reports[0]->routeSignature)->toBe('GET /api/mdv-passport')
        ->and($reports[0]->help)->toContain('integrations.passport.url');
});

it('says nothing when APP_URL names a host a client can actually reach', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/mdv-passport-public', [FormController::class, 'index'])->middleware('scope:read');

    config()->set('app.url', 'https://auth.acme.com');
    bindStubEngine();
    $result = generateDocument();

    $flows = $result->document->toArray()['components']['securitySchemes']['passport']['flows'];

    expect($flows['authorizationCode']['tokenUrl'])->toBe('https://auth.acme.com/oauth/token')
        ->and(diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE))->toBe([]);
});

it('says nothing when the document pins the OAuth base URL itself', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/mdv-passport-pinned', [FormController::class, 'index'])->middleware('scope:read');

    config()->set('app.url', 'http://localhost');
    bindStubEngine();
    $result = generateDocument(function (array $raw): array {
        $raw['integrations']['passport']['url'] = 'https://auth.acme.com';

        return $raw;
    });

    $flows = $result->document->toArray()['components']['securitySchemes']['passport']['flows'];

    expect($flows['authorizationCode']['tokenUrl'])->toBe('https://auth.acme.com/oauth/token')
        ->and(diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE))->toBe([]);
});

it('publishes its own fallback base URL when app.url holds nothing, and warns', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/mdv-passport-blank', [FormController::class, 'index'])->middleware('scope:read');

    config()->set('app.url', null);
    bindStubEngine();
    $result = generateDocument();

    $flows = $result->document->toArray()['components']['securitySchemes']['passport']['flows'];
    $reports = diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE);

    expect($flows['authorizationCode']['tokenUrl'])->toBe('http://localhost/oauth/token')
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->message)->toContain('fallback default')
        ->and($reports[0]->help)->toContain('integrations.passport.url');
});

it('publishes the environment-derived session cookie name, and warns once', function (): void {
    /** @var Router $router */
    $router = app('router');
    foreach (['api/mdv-sanctum', 'api/mdv-sanctum-two', 'api/mdv-sanctum-three'] as $uri) {
        $router->get($uri, [FormController::class, 'index'])
            ->middleware(['auth:sanctum', 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful']);
    }

    // Exactly what `Str::slug(env('APP_NAME'), '_').'_session'` — Laravel's shipped config/session.php —
    // gives an app that renamed itself and never set SESSION_COOKIE.
    config()->set('app.name', 'Acme CRM');
    config()->set('session.cookie', 'acme_crm_session');
    bindStubEngine();
    $result = generateDocument();

    $scheme = $result->document->toArray()['components']['securitySchemes']['sanctumStateful'];
    $reports = diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE);

    expect($scheme['name'])->toBe('acme_crm_session')
        ->and($scheme['description'])->toContain('acme_crm_session')
        // One cookie name is one fact, however many operations it reaches. Three routes, one report.
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->severity)->toBe(Severity::Warning)
        ->and($reports[0]->message)->toContain('acme_crm_session')
        ->and($reports[0]->message)->toContain('session.cookie')
        ->and($reports[0]->routeSignature)->toBeNull()
        ->and($reports[0]->help)->toContain('integrations.sanctum.cookie');
});

/**
 * An app whose `config/session.php` states a cookie name of its own has pinned it, so the document says
 * the same thing wherever it is built. Warning about that would red-light a correct production build
 * under `--fail-on=warning`, and a diagnostic that fires on correct code is a defect rather than a
 * stricter setting.
 */
it('says nothing when config/session.php names the cookie itself', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/mdv-sanctum-pinned-config', [FormController::class, 'index'])
        ->middleware(['auth:sanctum', 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful']);

    // The app is called Acme CRM, so the environment-derived default would be `acme_crm_session`.
    config()->set('app.name', 'Acme CRM');
    config()->set('session.cookie', 'myapp_session');
    bindStubEngine();
    $result = generateDocument();

    $scheme = $result->document->toArray()['components']['securitySchemes']['sanctumStateful'];

    expect($scheme['name'])->toBe('myapp_session')
        ->and(diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE))->toBe([]);
});

it('says nothing when the document pins the stateful cookie name itself', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/mdv-sanctum-pinned', [FormController::class, 'index'])
        ->middleware(['auth:sanctum', 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful']);

    config()->set('session.cookie', 'acme_crm_session');
    bindStubEngine();
    $result = generateDocument(function (array $raw): array {
        $raw['integrations']['sanctum']['cookie'] = 'pinned_session';

        return $raw;
    });

    $scheme = $result->document->toArray()['components']['securitySchemes']['sanctumStateful'];

    expect($scheme['name'])->toBe('pinned_session')
        ->and(diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE))->toBe([]);
});

it('publishes its own fallback cookie name when session.cookie holds nothing, and warns', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/mdv-sanctum-blank', [FormController::class, 'index'])
        ->middleware(['auth:sanctum', 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful']);

    config()->set('session.cookie', null);
    bindStubEngine();
    $result = generateDocument();

    $scheme = $result->document->toArray()['components']['securitySchemes']['sanctumStateful'];
    $reports = diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE);

    expect($scheme['name'])->toBe('laravel_session')
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->message)->toContain('fallback default');
});

it('says nothing about a cookie for a token-only Sanctum route, which publishes none', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/mdv-sanctum-token', [FormController::class, 'index'])->middleware('auth:sanctum');

    config()->set('session.cookie', 'acme_crm_session');
    bindStubEngine();
    $result = generateDocument();

    $schemes = $result->document->toArray()['components']['securitySchemes'];

    expect($schemes)->toHaveKey('sanctumToken')
        ->and($schemes)->not->toHaveKey('sanctumStateful')
        ->and(diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE))->toBe([]);
});

/**
 * The report is raised while the route's components are built, so it rides the operation fragment and
 * a warm hit replays it. Proving that needs a build where the route really did come back warm: fewer
 * diagnostics on a warm build is a silent degradation, and `--fail-on=warning` would then pass on a
 * cached build and fail on a fresh one for the same code.
 *
 * `WarmColdEqualityTest` holds the whole document to this; this row is the direct statement of it for
 * the security schemes, which are document-level and so the easiest thing to lose.
 */
it('replays the report on a warm build, where nothing re-reads the config', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/mdv-warm', [FormController::class, 'index'])->middleware('scope:read');

    config()->set('app.url', 'http://localhost');
    $dir = fragmentCacheDir('mdv');

    try {
        bindStubEngine();
        generateDocument();

        // An unwritten cache would make the second build a second cold one, and this would prove nothing.
        expect(glob($dir.'/*.json') ?: [])->not->toBeEmpty();

        bindStubEngine();
        $warm = generateDocument();
        $reports = diagnosticsCoded($warm->diagnostics, MachineDependentValue::CODE);

        expect($warm->document->toArray()['components']['securitySchemes'])->toHaveKey('passport')
            ->and($reports)->toHaveCount(1)
            ->and($reports[0]->message)->toContain('http://localhost');
    } finally {
        removeFragmentCacheDir($dir);
    }
});

it('says nothing at all when config declares the security schemes, since the author pinned them', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/mdv-declared', [FormController::class, 'index'])
        ->middleware(['auth:sanctum', 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful']);

    config()->set('app.url', 'http://localhost');
    config()->set('session.cookie', 'acme_crm_session');
    bindStubEngine();
    $result = generateDocument(function (array $raw): array {
        $raw['security'] = ['schemes' => ['apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key']]];

        return $raw;
    });

    expect(diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE))->toBe([]);
});
