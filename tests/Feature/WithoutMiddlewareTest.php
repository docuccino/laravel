<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * `withoutMiddleware(...)` exclusions (arch/qa §1.2): the route resolver mirrors Laravel's own
 * Router — an excluded middleware is removed from the gathered set, and one the Router does NOT
 * consider excluded stays — so a route that opts out of `throttle`/`auth` is not documented with the
 * 429/401 (or the security requirement) it never enforces, and one that opts out in a spelling the
 * framework does not equate keeps the 401 it does. Regression guard for the historically-ignored
 * `$route->excludedMiddleware()`.
 */
beforeEach(function (): void {
    bindStubEngine();

    $router = app('router');
    // Rate-limited but the throttle is explicitly excluded → no 429 should be documented.
    $router->get('api/opt-out-throttle', [FormController::class, 'index'])
        ->middleware('throttle:60,1')
        ->withoutMiddleware('throttle:60,1');
    // Authenticated but the auth guard is explicitly excluded → no 401 and no security requirement.
    $router->get('api/opt-out-auth', [FormController::class, 'index'])
        ->middleware('auth:web')
        ->withoutMiddleware('auth:web');
    // The same opt-out written in the OTHER spelling of the same middleware, both ways round. Laravel
    // resolves both sides through its alias map before subtracting, so at runtime each of these really
    // is unauthenticated — subtracting by the literal string left them carrying a 401 nothing enforces.
    $router->get('api/opt-out-auth-by-class', [FormController::class, 'index'])
        ->middleware('auth:web')
        ->withoutMiddleware(Authenticate::using('web'));
    $router->get('api/opt-out-auth-by-alias', [FormController::class, 'index'])
        ->middleware(Authenticate::using('web'))
        ->withoutMiddleware('auth:web');
    // One exclusion out of two middleware: the OTHER one has to survive. Nothing but a route carrying
    // two of them can tell a subtraction from a route that simply gathered nothing.
    $router->get('api/opt-out-one-of-two', [FormController::class, 'index'])
        ->middleware(['auth:web', 'throttle:60,1'])
        ->withoutMiddleware('throttle:60,1');
    // The same cross-spelling subtraction for the middleware that are not the authenticator: the
    // framework resolves these two sides to one class as well, so a document keeping them publishes a
    // 429 and a 403 the server never returns.
    $router->get('api/opt-out-throttle-by-class', [FormController::class, 'index'])
        ->middleware('throttle:60,1')
        ->withoutMiddleware(ThrottleRequests::class.':60,1');
    $router->get('api/opt-out-can-by-class', [FormController::class, 'index'])
        ->middleware(['auth:web', 'can:view'])
        ->withoutMiddleware(Authorize::using('view'));
    // And the two the framework does NOT equate: it compares its resolved names with the arguments
    // still attached, so `Authenticate` and `Authenticate:` are two middleware to it and neither of
    // these routes loses its authenticator. Reading both as "no arguments" dropped a 401 the server
    // does enforce.
    $router->get('api/opt-out-auth-empty-args', [FormController::class, 'index'])
        ->middleware('auth')
        ->withoutMiddleware('auth:');
    $router->get('api/opt-out-auth-bare', [FormController::class, 'index'])
        ->middleware('auth:')
        ->withoutMiddleware('auth');
    // A middleware that reaches the route through a GROUP, which reattaches its members' parameters on
    // truthiness where a route does it on `! is_null()`. So the two falsy parameter strings name a BARE
    // middleware inside a group and an argumented one on a route, and all three of these disagree with
    // the framework unless the expansion reads the group's grammar rather than the route's.
    $router->middlewareGroup('docuccino-zero-argument', ['auth:0']);
    $router->middlewareGroup('docuccino-empty-argument', ['auth:']);
    $router->middlewareGroup('docuccino-throttled', ['throttle:60,1']);
    $router->get('api/opt-out-group-zero-argument', [FormController::class, 'index'])
        ->middleware('docuccino-zero-argument')
        ->withoutMiddleware('auth');
    $router->get('api/opt-out-group-empty-argument', [FormController::class, 'index'])
        ->middleware('docuccino-empty-argument')
        ->withoutMiddleware('auth');
    // And the one the framework KEEPS: the group's member is bare, the route's exclusion is not.
    $router->get('api/opt-out-group-empty-argument-both-sides', [FormController::class, 'index'])
        ->middleware('docuccino-empty-argument')
        ->withoutMiddleware('auth:');
    // A group's ordinary member, subtracted by the class name the route never wrote.
    $router->get('api/opt-out-group-throttle', [FormController::class, 'index'])
        ->middleware('docuccino-throttled')
        ->withoutMiddleware(ThrottleRequests::class.':60,1');
    // The subclass fallback against the real Router rather than by hand: two BARE class names, the
    // excluded one a parent of the gathered one.
    $router->get('api/opt-out-subclass-by-parent', [FormController::class, 'index'])
        ->middleware(ApplicationAuthenticate::class)
        ->withoutMiddleware(Authenticate::class);
    $router->getRoutes()->refreshNameLookups();
});

it('drops excluded middleware from the resolved route descriptor', function (): void {
    $document = app(DocumentConfigFactory::class)
        ->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    $middlewareByUri = [];
    foreach (app(LaravelRouteResolver::class)->resolve($document) as $descriptor) {
        $middlewareByUri[$descriptor->uri] = $descriptor->middleware;
    }

    expect($middlewareByUri['/api/opt-out-throttle'] ?? null)->not->toContain('throttle:60,1')
        ->and($middlewareByUri['/api/opt-out-auth'] ?? null)->not->toContain('auth:web')
        ->and($middlewareByUri['/api/opt-out-auth-by-class'] ?? null)->toBe([])
        ->and($middlewareByUri['/api/opt-out-auth-by-alias'] ?? null)->toBe([])
        ->and($middlewareByUri['/api/opt-out-throttle-by-class'] ?? null)->toBe([])
        // The survivor, named: an exclusion removes the middleware it names and nothing else.
        ->and($middlewareByUri['/api/opt-out-one-of-two'] ?? null)->toBe(['auth:web'])
        ->and($middlewareByUri['/api/opt-out-can-by-class'] ?? null)->toBe(['auth:web'])
        // And the two the framework keeps.
        ->and($middlewareByUri['/api/opt-out-auth-empty-args'] ?? null)->toBe(['auth'])
        ->and($middlewareByUri['/api/opt-out-auth-bare'] ?? null)->toBe(['auth:'])
        // The group rows: a falsy parameter list inside a group is not part of the member's name, so
        // `auth` removes it — and `auth:`, which is not the same middleware, does not.
        ->and($middlewareByUri['/api/opt-out-group-zero-argument'] ?? null)->toBe([])
        ->and($middlewareByUri['/api/opt-out-group-empty-argument'] ?? null)->toBe([])
        ->and($middlewareByUri['/api/opt-out-group-empty-argument-both-sides'] ?? null)->toBe(['auth'])
        ->and($middlewareByUri['/api/opt-out-group-throttle'] ?? null)->toBe([])
        ->and($middlewareByUri['/api/opt-out-subclass-by-parent'] ?? null)->toBe([]);
});

/**
 * The subtraction against the authority it is a mirror of: whatever the framework's own Router keeps
 * for each of these routes, we keep ({@see assertMiddlewareAgreesWithRouter()} for how the two sides
 * are made comparable).
 */
it('keeps exactly what the framework\'s own router keeps', function (): void {
    assertMiddlewareAgreesWithRouter('/api/opt-out-', 14);
});

it('documents no 429 for a route that excludes its throttle middleware', function (string $uri): void {
    $document = generateDocument()->document->toArray();

    expect($document['paths'][$uri]['get']['responses'])->not->toHaveKey('429');
})->with([
    'same spelling both sides' => ['/api/opt-out-throttle'],
    'excluded by the class name' => ['/api/opt-out-throttle-by-class'],
]);

it('documents no 403 for a route that excludes its authorization middleware', function (): void {
    $document = generateDocument()->document->toArray();

    expect($document['paths']['/api/opt-out-can-by-class']['get']['responses'])->not->toHaveKey('403');
});

/**
 * The published 401 and security requirement, both directions in ONE document: the routes that opted
 * out carry neither, and the route whose exclusion named its OTHER middleware carries both. Indexed
 * rather than defaulted — a `?? []` here passes for a route that was never registered at all — and the
 * document is given a `security.default` so the requirement half is a real assertion instead of a fact
 * about a document that publishes no requirements anywhere.
 */
it('publishes the 401 and the security requirement for exactly the routes that still enforce them', function (): void {
    $document = generateDocument(static function (array $raw): array {
        $raw['security'] = [
            'schemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
            'auto_detect_middleware' => 'auth*',
            'default' => [['bearer' => []]],
        ];

        return $raw;
    })->document->toArray();

    $unauthenticated = ['/api/opt-out-auth', '/api/opt-out-auth-by-class', '/api/opt-out-auth-by-alias'];
    foreach ($unauthenticated as $uri) {
        $operation = $document['paths'][$uri]['get'];
        expect($operation['responses'])->not->toHaveKey('401')
            ->and($operation)->not->toHaveKey('security');
    }

    $authenticated = [
        // The exclusion named the throttle, so the authenticator is untouched.
        '/api/opt-out-one-of-two',
        '/api/opt-out-can-by-class',
        // And the two spellings the framework does not equate.
        '/api/opt-out-auth-empty-args',
        '/api/opt-out-auth-bare',
    ];
    foreach ($authenticated as $uri) {
        $operation = $document['paths'][$uri]['get'];
        expect($operation['responses'])->toHaveKey('401')
            ->and($operation['security'])->toBe([['bearer' => []]]);
    }
});

/**
 * The same answer for a router the HTTP kernel has never synced, which is the state a console build
 * finds: the alias map is empty until that constructor runs. A reader of the router's own map alone
 * stops equating the two spellings of one middleware there, and the workbench's `api/unguarded-forms`
 * — which opts out of its authenticator in the other spelling — gets back a 401 it does not enforce.
 */
it('answers the same for a router the HTTP kernel has never synced', function (): void {
    $this->refreshApplication();
    bindStubEngine();

    // The premise, from the framework: this really is what a console build reads.
    expect(app('router')->getMiddleware())->toBe([]);

    $paths = generateDocument()->document->toArray()['paths'];

    expect($paths['/api/unguarded-forms']['get']['responses'])->not->toHaveKey('401')
        // Anti-vacuity: this document does publish 401s, on the route beside it whose authenticator
        // nothing excluded.
        ->and($paths['/api/guarded-forms']['get']['responses'])->toHaveKey('401');
});
