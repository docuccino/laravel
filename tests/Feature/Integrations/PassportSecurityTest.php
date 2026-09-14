<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Real-path coverage for Passport guard-driver detection and client-credentials routes (auth audit
 * #7, #8): the extension reads the actual gathered middleware and the app's `config('auth.guards')`
 * through the pipeline. A `passport`-driver guard (any name, in a multi-guard list) is claimed; an
 * `api` guard on a token driver is not; client-credentials middleware is Passport-protected with its
 * parsed scopes. Passport is a dev dependency, so the integration is registered.
 */
function passportDocument(array $guards): array
{
    config()->set('auth.guards', $guards + ['web' => ['driver' => 'session', 'provider' => 'users']]);

    bindStubEngine();

    return generateDocument()->document->toArray();
}

it('claims a custom passport-driver guard by driver, not name', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/partner-data', [FormController::class, 'index'])->middleware('auth:partner');

    $document = passportDocument(['partner' => ['driver' => 'passport']]);

    expect($document['components']['securitySchemes']['passport']['type'])->toBe('oauth2')
        ->and($document['paths']['/api/partner-data']['get']['security'])->toBe([['passport' => []]]);
});

it('claims a passport-driver guard inside a multi-guard list', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/partner-multi', [FormController::class, 'index'])->middleware('auth:web,partner');

    $document = passportDocument(['partner' => ['driver' => 'passport']]);

    expect($document['paths']['/api/partner-multi']['get']['security'])->toBe([['passport' => []]]);
});

it('does not claim an api guard whose driver is token', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/token-guard', [FormController::class, 'index'])->middleware('auth:api');

    $document = passportDocument(['api' => ['driver' => 'token']]);

    expect($document['components']['securitySchemes'] ?? [])->not->toHaveKey('passport')
        ->and($document['paths']['/api/token-guard']['get'] ?? [])->not->toHaveKey('security');
});

it('documents a client-credentials route with its parsed scopes', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/machine', [FormController::class, 'index'])->middleware('client:read,write');

    $document = passportDocument([]);

    expect($document['components']['securitySchemes']['passport']['flows'])->toHaveKey('clientCredentials')
        ->and($document['paths']['/api/machine']['get']['security'])->toBe([['passport' => ['read', 'write']]]);
});

it('protects a bare client-credentials route with no scopes', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/machine-bare', [FormController::class, 'index'])->middleware('client');

    $document = passportDocument([]);

    expect($document['paths']['/api/machine-bare']['get']['security'])->toBe([['passport' => []]]);
});

/*
 * A published URL carries no credentials. An `APP_URL` holding HTTP basic-auth userinfo is an
 * ordinary way to reach a service behind a gateway, and the flow URLs the document publishes are
 * endpoints a generated client calls — so the userinfo is a secret riding into a committed artifact
 * that nothing in the endpoint's identity needs. The URL still publishes, stripped: OAS requires a
 * `tokenUrl` on every flow object and a local preview has to keep working.
 */
function passportFlowUrls(array $raw): array
{
    /** @var Router $router */
    $router = app('router');
    $router->get('api/credentialed', [FormController::class, 'index'])->middleware('client');

    config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);
    bindStubEngine();

    $result = generateDocument(static fn (array $settings): array => array_replace_recursive($settings, $raw));

    return [$result->document->toArray()['components']['securitySchemes']['passport']['flows'], $result->diagnostics];
}

it('publishes no credentials in a flow URL read from the application URL', function (): void {
    config()->set('app.url', 'https://svc:s3cr3t@api.acme.com');

    [$flows, $diagnostics] = passportFlowUrls([]);

    expect($flows['authorizationCode']['tokenUrl'])->toBe('https://api.acme.com/oauth/token')
        ->and($flows['authorizationCode']['authorizationUrl'])->toBe('https://api.acme.com/oauth/authorize')
        ->and($flows['authorizationCode']['refreshUrl'])->toBe('https://api.acme.com/oauth/token')
        ->and($flows['clientCredentials']['tokenUrl'])->toBe('https://api.acme.com/oauth/token');

    $reported = array_values(array_filter(
        $diagnostics,
        static fn ($d): bool => str_contains($d->message, 'credential') || str_contains((string) $d->help, 'credential'),
    ));

    expect($reported)->not->toBeEmpty();

    foreach ($diagnostics as $diagnostic) {
        expect($diagnostic->message)->not->toContain('s3cr3t')
            ->and((string) $diagnostic->help)->not->toContain('s3cr3t');
    }
});

it('publishes no credentials in a flow URL read from the document pin', function (): void {
    config()->set('app.url', 'https://api.acme.com');

    [$flows, $diagnostics] = passportFlowUrls(['integrations' => ['passport' => ['url' => 'https://svc:s3cr3t@auth.acme.com']]]);

    expect($flows['clientCredentials']['tokenUrl'])->toBe('https://auth.acme.com/oauth/token');

    foreach ($diagnostics as $diagnostic) {
        expect($diagnostic->message)->not->toContain('s3cr3t')
            ->and((string) $diagnostic->help)->not->toContain('s3cr3t');
    }
});

it('leaves a flow URL carrying no credentials exactly as the application configured it', function (): void {
    config()->set('app.url', 'https://api.acme.com/v1');

    [$flows, $diagnostics] = passportFlowUrls([]);

    expect($flows['clientCredentials']['tokenUrl'])->toBe('https://api.acme.com/v1/oauth/token')
        ->and(array_filter($diagnostics, static fn ($d): bool => str_contains($d->message, 'credential')))->toBe([]);
});
