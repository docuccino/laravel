<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/*
 * Security scheme configuration → document (design §Auth detection / §Security scheme breadth):
 * the scheme catalogue, document-level and per-operation requirements, middleware auto-detection,
 * and the #[Unauthenticated] opt-out. Uses the shared `stubDocumentArray()` (tests/Pest.php).
 */

it('emits the full scheme set and a document-level requirement', function (): void {
    $document = stubDocumentArray(function (array $raw): array {
        $raw['security']['schemes'] = [
            'bearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
            'basic' => ['type' => 'http', 'scheme' => 'basic'],
            'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            'oauth2' => ['type' => 'oauth2', 'flows' => [
                'authorizationCode' => [
                    'authorizationUrl' => 'https://example.test/oauth/authorize',
                    'tokenUrl' => 'https://example.test/oauth/token',
                    'scopes' => ['read' => 'Read access'],
                ],
            ]],
            'oidc' => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://example.test/.well-known/openid-configuration'],
        ];
        $raw['security']['document'] = [['bearer' => []]];

        return $raw;
    });

    expect($document['components']['securitySchemes'])->toHaveKeys(['bearer', 'basic', 'apiKey', 'oauth2', 'oidc'])
        ->and($document['components']['securitySchemes']['apiKey'])->toBe(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'])
        ->and($document['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'])->toBe(['read' => 'Read access'])
        ->and($document['security'])->toBe([['bearer' => []]]);
});

it('applies the default requirement to auth-detected routes only, and clears it for #[Unauthenticated]', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/protected', [FormController::class, 'index'])->middleware('auth:sanctum');

    $document = stubDocumentArray(function (array $raw): array {
        $raw['security']['schemes'] = ['bearer' => ['type' => 'http', 'scheme' => 'bearer']];
        $raw['security']['default'] = [['bearer' => []]];

        return $raw;
    });

    // The auth-middleware route inherits the default requirement...
    expect($document['paths']['/api/protected']['get']['security'])->toBe([['bearer' => []]])
        // ...a route with no auth middleware carries none...
        ->and($document['paths']['/api/forms']['get'])->not->toHaveKey('security')
        // ...and #[Unauthenticated] explicitly opts out.
        ->and($document['paths']['/api/widgets']['post']['security'])->toBe([]);
});

it('supports a multi-scheme AND requirement', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/protected', [FormController::class, 'index'])->middleware('auth');

    $document = stubDocumentArray(function (array $raw): array {
        // Both schemes are catalogued: a requirement naming one that isn't is an invalid document, and
        // its own report ({@see SecurityAuditTest}).
        $raw['security']['schemes'] = [
            'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
            'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
        ];
        $raw['security']['default'] = [['bearer' => [], 'apiKey' => []]];

        return $raw;
    });

    // A single requirement object listing two schemes means BOTH are required.
    expect($document['paths']['/api/protected']['get']['security'])->toBe([['bearer' => [], 'apiKey' => []]]);
});

it('emits no security by default', function (): void {
    $document = stubDocumentArray(static fn (array $raw): array => $raw);

    expect($document)->not->toHaveKey('security')
        ->and($document['components'] ?? [])->not->toHaveKey('securitySchemes');
});

/**
 * The `auth_middleware` pattern is written in the framework's vocabulary, and an application's own alias
 * for an authenticator is that same middleware: it refuses a guest with the 401 the route documents, so it
 * owes the requirement that 401 asks for. A pattern narrowed to one guard stays narrowed under the alias.
 */
it('applies the default requirement to an authenticator whatever alias the application gave it', function (string $pattern, string $authenticator, bool $applied): void {
    /** @var Router $router */
    $router = app('router');
    $router->aliasMiddleware('signed-in', ApplicationAuthenticate::class);
    $router->aliasMiddleware('fw-signed-in', Authenticate::class);
    $router->aliasMiddleware('throttled', ThrottleRequests::class);
    $router->get('api/protected', [FormController::class, 'index'])->middleware($authenticator);

    $document = stubDocumentArray(function (array $raw) use ($pattern): array {
        $raw['security']['schemes'] = ['bearer' => ['type' => 'http', 'scheme' => 'bearer']];
        $raw['security']['default'] = [['bearer' => []]];
        $raw['security']['auth_middleware'] = $pattern;

        return $raw;
    });

    expect($document['paths']['/api/protected']['get']['security'] ?? null)->toBe($applied ? [['bearer' => []]] : null);
})->with([
    'the auth alias' => ['auth*', 'auth', true],
    'the class spelling' => ['auth*', Authenticate::using('web'), true],
    'the application subclass by class' => ['auth*', ApplicationAuthenticate::class, true],
    'an application alias of the framework authenticator' => ['auth*', 'fw-signed-in', true],
    'an application alias of its own subclass' => ['auth*', 'signed-in', true],
    'an application alias naming a guard' => ['auth*', 'signed-in:web', true],
    'the session variant, which the pattern has always named' => ['auth*', 'auth.session', true],
    'a pattern narrowed to the guard the alias names' => ['auth:web', 'signed-in:web', true],
    'a pattern narrowed to another guard' => ['auth:api', 'signed-in:web', false],
    'an alias of middleware that does not authenticate' => ['auth*', 'throttled:60,1', false],
]);
