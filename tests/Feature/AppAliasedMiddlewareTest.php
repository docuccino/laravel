<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Routing\RouteContextBuilder;
use Docuccino\Laravel\Support\MiddlewareResolution;
use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Spatie\Permission\Middleware\RoleMiddleware;
use Workbench\App\Http\Controllers\FormController;

/**
 * A middleware named by its class, in an application that has registered an alias of its own for that
 * class. Every reader downstream is a table of NAMES — a vendor's aliases beside its class names, the
 * framework's alias beside its own — so an entry handed on in a third vocabulary, the application's
 * alias, is a middleware nobody recognises, and the abilities, the scopes, the role and the 401 all
 * disappear together while the server goes on enforcing them. What the application's map is for is
 * subtraction ({@see MiddlewareResolution}), not renaming.
 *
 * Asserted on the published document, for each family of reader, because the descriptor's middleware
 * list is not the axis: a list can be perfectly resolved and still name the middleware in a spelling
 * every reader of it is blind to.
 */
beforeEach(function (): void {
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $abilities = 'Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities';
    // Passport 13 renamed its scope middleware, so which spelling an application writes is a function
    // of the major it resolved.
    $scopes = class_exists('Laravel\\Passport\\Http\\Middleware\\CheckToken')
        ? 'Laravel\\Passport\\Http\\Middleware\\CheckToken'
        : 'Laravel\\Passport\\Http\\Middleware\\CheckScopes';

    // The application's own names for four middleware it did not write. `auth` against its own
    // subclass is the ≤10 skeleton's default; the rest are what an application does when it prefers
    // its own vocabulary to a vendor's.
    $router->aliasMiddleware('auth', ApplicationAuthenticate::class);
    $router->aliasMiddleware('fwauth', Authenticate::class);
    $router->aliasMiddleware('token-abilities', $abilities);
    $router->aliasMiddleware('oauth-scopes', $scopes);
    $router->aliasMiddleware('app-role', RoleMiddleware::class);
    $router->aliasMiddleware('hits', ThrottleRequests::class);

    $router->get('api/app-aliased/abilities', [FormController::class, 'index'])
        ->middleware($abilities.':orders:read,orders:write');
    $router->get('api/app-aliased/scopes', [FormController::class, 'index'])
        ->middleware($scopes.':read-orders');
    $router->get('api/app-aliased/role', [FormController::class, 'index'])
        ->middleware(RoleMiddleware::class.':admin');
    // The framework's own authenticator, under an alias that is not the framework's.
    $router->get('api/app-aliased/authenticator', [FormController::class, 'index'])
        ->middleware(Authenticate::using('web'));
    // And the application's own, under the alias that is.
    $router->get('api/app-aliased/own-authenticator', [FormController::class, 'index'])
        ->middleware(ApplicationAuthenticate::using('web'));
    // A hand-written class string carries the leading `\` no `::class` renders. It names the same
    // class, so the same facts are owed.
    $router->get('api/app-aliased/backslashed-role', [FormController::class, 'index'])
        ->middleware('\\'.RoleMiddleware::class.':admin');
    $router->get('api/app-aliased/backslashed-throttle', [FormController::class, 'index'])
        ->middleware('\\'.ThrottleRequests::class.':60,1');
    $router->getRoutes()->refreshNameLookups();
});

it('publishes what each family of reader recovers from a middleware the application aliased', function (): void {
    $paths = generateDocument()->document->toArray()['paths'];

    expect($paths['/api/app-aliased/abilities']['get']['x-abilities'])
        ->toBe([['match' => 'all', 'abilities' => ['orders:read', 'orders:write']]])
        ->and($paths['/api/app-aliased/role']['get']['x-permissions'])
        ->toBe([['type' => 'role', 'values' => ['admin']]])
        ->and($paths['/api/app-aliased/scopes']['get']['security'])
        ->toBe([['passport' => ['read-orders']]])
        // The authenticator's own two facts, in both directions: the framework's class under an alias
        // that is not the framework's, and the application's subclass under the alias that is.
        ->and($paths['/api/app-aliased/authenticator']['get']['responses'])->toHaveKey('401')
        ->and($paths['/api/app-aliased/own-authenticator']['get']['responses'])->toHaveKey('401')
        // Anti-vacuity: this document does leave a route without a 401.
        ->and($paths['/api/app-aliased/role']['get']['responses'])->not->toHaveKey('401');
});

it('publishes the same facts for a class string written with its leading separator', function (): void {
    $paths = generateDocument()->document->toArray()['paths'];

    expect($paths['/api/app-aliased/backslashed-role']['get']['x-permissions'])
        ->toBe([['type' => 'role', 'values' => ['admin']]])
        ->and($paths['/api/app-aliased/backslashed-throttle']['get']['responses'])->toHaveKey('429');
});

/**
 * The security requirement half, which needs a document that configures a scheme to have one at all.
 */
it('publishes the security requirement for a route authenticated under either alias', function (): void {
    $paths = generateDocument(static function (array $raw): array {
        $raw['security'] = [
            'schemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
            'auto_detect_middleware' => 'auth*',
            'default' => [['bearer' => []]],
        ];

        return $raw;
    })->document->toArray()['paths'];

    expect($paths['/api/app-aliased/authenticator']['get']['security'])->toBe([['bearer' => []]])
        ->and($paths['/api/app-aliased/own-authenticator']['get']['security'])->toBe([['bearer' => []]])
        ->and($paths['/api/app-aliased/role']['get'])->not->toHaveKey('security');
});

/**
 * The hierarchy those readings come off has FILES behind it, and they key the fragment: a middleware
 * that stops extending the framework's authenticator publishes a different document while moving
 * nothing else the key holds — not the route, not the action, not the middleware list.
 */
it('keys a route fragment on the files its middleware classes declare', function (): void {
    $document = app(DocumentConfigFactory::class)
        ->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    $context = app(RouteContextBuilder::class)->build(
        new RouteDescriptor(['GET', 'HEAD'], '/api/app-aliased/own-authenticator', middleware: [ApplicationAuthenticate::using('web')]),
        $document,
        new NullTypeEngine,
        new ResolvedExtensions,
        new ComponentRegistry,
    );

    expect($context)->not->toBeNull()
        ->and($context->dependencies()->files())
        ->toContain((string) (new ReflectionClass(ApplicationAuthenticate::class))->getFileName())
        // …and its parent's, since the parent is what makes it an authenticator at all.
        ->toContain((string) (new ReflectionClass(Authenticate::class))->getFileName());
});
