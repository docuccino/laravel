<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Docuccino\Laravel\Support\MiddlewareAliases;
use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * An application that registers `auth` against its OWN `Authenticate` subclass — the Laravel ≤10
 * skeleton's default, carried into every application upgraded from one. Which two strings name one
 * middleware is then the application's fact rather than the framework's, and what a reader of the
 * wrong map costs is stated in {@see MiddlewareAliases}.
 *
 * What this proves is the subtraction in both directions under that map, the document published from
 * it — the 401 and the requirement on exactly the routes the framework still authenticates — and what
 * is said where the map could not be read at all.
 */
beforeEach(function (): void {
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->aliasMiddleware('auth', ApplicationAuthenticate::class);

    // The application's own authenticator, written the way its static constructor renders it.
    $router->get('api/aliased/by-app-class', [FormController::class, 'index'])
        ->middleware(ApplicationAuthenticate::using('web'));
    // The alias, excluded by the class it is registered against: the framework drops it.
    $router->get('api/aliased/excluded-by-app-class', [FormController::class, 'index'])
        ->middleware('auth:web')
        ->withoutMiddleware(ApplicationAuthenticate::using('web'));
    // The alias, excluded by the framework class it is NOT registered against: the framework keeps it,
    // so the route still authenticates and still owes its 401.
    $router->get('api/aliased/excluded-by-framework-class', [FormController::class, 'index'])
        ->middleware('auth:web')
        ->withoutMiddleware(Authenticate::using('web'));
    // And an exclusion naming an alias no map here explains — a misspelling, or one registered somewhere
    // this application never reaches — which subtracts nothing and leaves the route carrying a response
    // nothing enforces.
    $router->get('api/aliased/excluded-by-unreadable-alias', [FormController::class, 'index'])
        ->middleware('auth:web')
        ->withoutMiddleware('tenant:acme');
    $router->getRoutes()->refreshNameLookups();
});

it('subtracts a route\'s middleware through the application\'s alias map, not the framework\'s', function (): void {
    $document = app(DocumentConfigFactory::class)
        ->make('default', documentSettings(), 'skeleton');

    $middleware = [];
    foreach (app(LaravelRouteResolver::class)->resolve($document) as $descriptor) {
        $middleware[$descriptor->uri] = $descriptor->middleware;
    }

    expect($middleware['/api/aliased/by-app-class'] ?? null)->toBe([ApplicationAuthenticate::class.':web'])
        ->and($middleware['/api/aliased/excluded-by-app-class'] ?? null)->toBe([])
        ->and($middleware['/api/aliased/excluded-by-framework-class'] ?? null)->toBe(['auth:web'])
        ->and($middleware['/api/aliased/excluded-by-unreadable-alias'] ?? null)->toBe(['auth:web']);
});

/** The same rows against the authority: whatever the framework's own router keeps, we keep. */
it('agrees with the framework\'s own router under an application alias', function (): void {
    assertMiddlewareAgreesWithRouter('/api/aliased/', 4);
});

/**
 * And what the document publishes, which is the reason any of it matters: the two routes the framework
 * still authenticates carry the 401 and the security requirement, and the one it does not carries
 * neither. Both directions in one document, so neither half can pass on a document that publishes no
 * requirements at all.
 */
it('publishes the 401 and the requirement for the routes the application really authenticates', function (): void {
    $document = generateDocument(static function (array $raw): array {
        $raw['security'] = [
            'schemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
            'auth_middleware' => 'auth*',
            'default' => [['bearer' => []]],
        ];

        return $raw;
    })->document->toArray();

    foreach (['/api/aliased/by-app-class', '/api/aliased/excluded-by-framework-class'] as $uri) {
        $operation = $document['paths'][$uri]['get'];
        expect($operation['responses'])->toHaveKey('401')
            ->and($operation['security'])->toBe([['bearer' => []]]);
    }

    $optedOut = $document['paths']['/api/aliased/excluded-by-app-class']['get'];
    expect($optedOut['responses'])->not->toHaveKey('401')
        ->and($optedOut)->not->toHaveKey('security');
});

/**
 * And what it says where the map it could not read is what decided the answer: an exclusion that
 * removed nothing and named nothing this build can resolve is reported, and one that resolved is not —
 * a count rather than a presence, so a report that fired on every route would fail here too.
 */
it('says which exclusions the alias map could not vouch for', function (): void {
    $reported = diagnosticsCoded(generateDocument()->diagnostics, 'route.unmatched-exclusion');

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->severity)->toBe(Severity::Warning)
        ->and($reported[0]->routeSignature)->toBe('GET /api/aliased/excluded-by-unreadable-alias')
        ->and($reported[0]->message)->toContain('tenant:acme');
});
