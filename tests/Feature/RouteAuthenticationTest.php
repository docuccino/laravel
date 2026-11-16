<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Integrations\Passport\PassportIntegration;
use Docuccino\Laravel\Integrations\Permission\PermissionIntegration;
use Docuccino\Laravel\Integrations\Sanctum\SanctumIntegration;
use Docuccino\Laravel\Registry\IntegrationToggles;
use Docuccino\Laravel\Support\RouteAuthentication;
use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use Laravel\Passport\Token;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * {@see RouteAuthentication}'s table, row by row, and the reason each row is right: the middleware is run
 * against a guest's JSON request and refuses it with exactly the exception the table names.
 */
function routeAuthenticationContext(array $middleware, ?string $pattern = null, array $integrations = []): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things', middleware: $middleware),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet([]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], authMiddleware: $pattern, raw: ['integrations' => $integrations]),
    );
}

/** @return array<class-string, class-string> */
function routeAuthenticationTable(): array
{
    /** @var array<class-string, class-string> */
    return (new ReflectionClassConstant(RouteAuthentication::class, 'REFUSALS'))->getValue();
}

/** What the middleware does with a guest's JSON request: the class of what it throws, or null if it lets it through. */
function guestRefusal(object $middleware, string ...$arguments): ?string
{
    $request = Request::create('/api/things', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setLaravelSession(app('session.store'));

    return middlewareThrows($middleware, $request, $arguments);
}

/** @param  list<string>  $arguments */
function middlewareThrows(object $middleware, Request $request, array $arguments): ?string
{
    try {
        $middleware->handle($request, static fn (): Response => new Response, ...$arguments);
    } catch (Throwable $e) {
        return $e::class;
    }

    return null;
}

/** @return array<class-string, array{refusal: class-string, denial: class-string, signal: string}> */
function packageAuthenticators(): array
{
    return [...PassportIntegration::authenticators(), ...SanctumIntegration::authenticators()];
}

/**
 * Passport booted as an application boots it, with a key of its own: its resource server refuses a request
 * with no token before the key is read, by the same validator a deployed application runs.
 */
function bootPassport(): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    expect($key)->not->toBeFalse();
    config()->set('passport.public_key', openssl_pkey_get_details($key)['key']);
    config()->set('auth.guards.api', ['driver' => 'passport', 'provider' => 'users']);
    app()->register(PassportServiceProvider::class);
}

/**
 * What the middleware does with a request whose token lacks the `read` scope or ability the entry names,
 * the token set up the way each package's own testing helpers set one up: a user's token on the request
 * for the middleware that reads the user, and a client token behind the resource server for those that
 * validate the bearer token themselves.
 */
function tokenDenial(string $class): ?string
{
    $request = Request::create('/api/things', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setLaravelSession(app('session.store'));

    if (str_starts_with($class, 'Laravel\\Sanctum\\')) {
        $user = new class extends User
        {
            use HasApiTokens;
        };
        $user->withAccessToken(new PersonalAccessToken(['abilities' => ['other']]));
        $request->setUserResolver(static fn (): User => $user);

        return middlewareThrows(app($class), $request, ['read']);
    }

    bootPassport();

    $readsTheUser = ['CheckScopes', 'CheckForAnyScope', 'CheckToken', 'CheckTokenForAnyScope'];
    if (in_array(class_basename($class), $readsTheUser, true)) {
        $user = new class extends User
        {
            use Laravel\Passport\HasApiTokens;
        };
        $user->withAccessToken(class_exists('Laravel\\Passport\\AccessToken')
            ? new AccessToken(['oauth_scopes' => ['other']])
            : new Token(['scopes' => ['other']]));
        $request->setUserResolver(static fn (): User => $user);

        return middlewareThrows(app($class), $request, ['read']);
    }

    Passport::actingAsClient((new Client)->forceFill(['id' => 1]), ['other']);

    return middlewareThrows(app($class), $request, ['read']);
}

it('names the exception each authenticator really throws at a guest', function (string $class): void {
    expect(guestRefusal(app($class)))->toBe(routeAuthenticationTable()[$class]);
})->with(array_keys(routeAuthenticationTable()));

/**
 * An auth package's own middleware reads the token itself, so a route behind it alone refuses a request
 * without one; and each throws its own exception at a token lacking a scope or ability the entry names.
 * Every row the installed packages answer with is run both ways.
 */
it("names what each auth package's middleware throws at a guest", function (string $class): void {
    bootPassport();

    expect(guestRefusal(app($class), 'read'))->toBe(packageAuthenticators()[$class]['refusal']);
})->with(array_keys(packageAuthenticators()));

it("names what each auth package's middleware throws at a token lacking what it names", function (string $class): void {
    expect(tokenDenial($class))->toBe(packageAuthenticators()[$class]['denial']);
})->with(array_keys(packageAuthenticators()));

/**
 * The union of the rows above and the classes that check no token, against every middleware class the
 * installed packages ship: a class added by an upgrade must land on one side or the other. The abstract
 * bases are what the listed classes extend, never routed themselves.
 */
it('accounts for every middleware class the installed auth packages ship', function (): void {
    $passes = [
        'Laravel\\Passport\\Http\\Middleware\\CreateFreshApiToken',
        'Laravel\\Sanctum\\Http\\Middleware\\AuthenticateSession',
        'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful',
    ];
    $abstract = [
        'Laravel\\Passport\\Http\\Middleware\\CheckCredentials',
        'Laravel\\Passport\\Http\\Middleware\\ValidateToken',
    ];

    $shipped = [];
    foreach ([Passport::class, CheckAbilities::class] as $anchor) {
        $root = dirname((string) (new ReflectionClass($anchor))->getFileName());
        $directory = str_ends_with($root, 'Middleware') ? $root : $root.'/Http/Middleware';
        $namespace = str_ends_with($root, 'Middleware') ? (new ReflectionClass($anchor))->getNamespaceName() : (new ReflectionClass($anchor))->getNamespaceName().'\\Http\\Middleware';
        foreach ((array) glob($directory.'/*.php') as $file) {
            $shipped[] = $namespace.'\\'.basename((string) $file, '.php');
        }
    }
    sort($shipped);

    $accounted = [...array_keys(packageAuthenticators()), ...array_filter([...$passes, ...$abstract], class_exists(...))];
    sort($accounted);

    expect($accounted)->toBe($shipped)
        ->and(count($shipped))->toBeGreaterThan(8);
});

it('leaves out the auth packages\' middleware that lets a guest through', function (string $class): void {
    expect(guestRefusal(app($class)))->toBeNull()
        ->and(app(RouteAuthentication::class)->refusal(routeAuthenticationContext([$class])))->toBeNull();
})->with([
    'Laravel\\Sanctum\\Http\\Middleware\\AuthenticateSession',
    'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful',
]);

it("answers an auth package's middleware under any alias the application gives it", function (): void {
    $authentication = app(RouteAuthentication::class);
    app('router')->aliasMiddleware('token-can', CheckAbilities::class);

    expect($authentication->refusal(routeAuthenticationContext(['token-can:read'])))->toBe('Illuminate\\Auth\\AuthenticationException')
        ->and($authentication->denial(routeAuthenticationContext(['token-can:read']), 'token-can:read'))
        ->toBe(['signal' => 'token-middleware', 'exception' => 'Laravel\\Sanctum\\Exceptions\\MissingAbilityException'])
        ->and($authentication->refusal(routeAuthenticationContext([CheckAbilities::class.':read'])))->toBe('Illuminate\\Auth\\AuthenticationException')
        // Anti-vacuity: the alias names nothing until the application registers it.
        ->and($authentication->refusal(routeAuthenticationContext(['other-alias:read'])))->toBeNull();
});

/** An entry naming no scope checks the token and nothing past it, so it denies nothing a token holds. */
it('finds no denial on an entry that names no scope, or on middleware that is not a token check', function (string $middleware): void {
    expect(app(RouteAuthentication::class)->denial(routeAuthenticationContext([$middleware]), $middleware))->toBeNull();
})->with([
    'bare ability middleware' => CheckAbilities::class,
    'the framework authenticator' => 'auth:sanctum',
    'a gate' => 'can:view,post',
]);

/**
 * spatie/laravel-permission's middleware reads the user itself, and throws the same 403 at a guest as at a
 * member lacking every role or permission the entry names. So it names no refusal, which is the 401's, and
 * its denial is what both get.
 */
it("names what spatie's middleware throws at a guest and at a member lacking what it names", function (string $class): void {
    $member = bootPermissionTables();
    $thrown = PermissionIntegration::authenticators()[$class];
    $answers = static function (object $middleware): array {
        $request = Request::create('/api/things', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        try {
            $middleware->handle($request, static fn (): Response => new Response, 'edit');
        } catch (HttpExceptionInterface $e) {
            return [$e::class, $e->getStatusCode()];
        }

        return [null, 200];
    };

    $guest = $answers(app($class));
    $this->actingAs($member);

    expect($thrown['refusal'])->toBeNull()
        ->and($guest)->toBe([$thrown['denial'], 403])
        ->and($answers(app($class)))->toBe([$thrown['denial'], 403]);
})->with(array_keys(PermissionIntegration::authenticators()));

it('accounts for every middleware class spatie/laravel-permission ships', function (): void {
    $directory = dirname((string) (new ReflectionClass(PermissionMiddleware::class))->getFileName());
    $shipped = array_map(static fn (string $file): string => 'Spatie\\Permission\\Middleware\\'.basename($file, '.php'), (array) glob($directory.'/*.php'));
    $listed = array_keys(PermissionIntegration::authenticators());
    sort($shipped);
    sort($listed);

    expect($listed)->toBe($shipped)
        ->and(count($shipped))->toBeGreaterThanOrEqual(3);
});

/**
 * A guest meets spatie's 403 rather than a 401, so the route is refused to a guest without answering 401;
 * an authenticator in front of it still answers its own. Read by class under the application's alias, and
 * only where the document enables the integration, which is off unless asked for.
 */
it("reads spatie's middleware as refusing a guest with its 403 and no 401", function (): void {
    $authentication = app(RouteAuthentication::class);
    app('router')->aliasMiddleware('permission', PermissionMiddleware::class);
    $enabled = ['permission' => ['enabled' => true]];
    $alone = routeAuthenticationContext(['permission:edit'], integrations: $enabled);

    expect($authentication->refusal($alone))->toBeNull()
        ->and($authentication->refusesGuests($alone))->toBeTrue()
        ->and($authentication->denial($alone, 'permission:edit'))
        ->toBe(['signal' => 'permission-middleware', 'exception' => UnauthorizedException::class])
        ->and($authentication->refusal(routeAuthenticationContext(['permission:edit', 'auth'], integrations: $enabled)))
        ->toBe('Illuminate\\Auth\\AuthenticationException')
        // Anti-vacuity: the same route in a document that leaves the integration off.
        ->and($authentication->refusesGuests(routeAuthenticationContext(['permission:edit'])))->toBeFalse()
        ->and($authentication->denial(routeAuthenticationContext(['permission:edit']), 'permission:edit'))->toBeNull();
});

/** A disabled integration contributes nothing to the document, and that includes what its middleware throws. */
it("drops an auth package's middleware from a document that disables its integration", function (): void {
    $authentication = app(RouteAuthentication::class);
    $disabled = routeAuthenticationContext([CheckAbilities::class.':read'], integrations: ['sanctum' => ['enabled' => false]]);

    expect($authentication->refusal($disabled))->toBeNull()
        ->and($authentication->denial($disabled, CheckAbilities::class.':read'))->toBeNull()
        ->and(IntegrationToggles::authenticators($disabled->document))->toBe(PassportIntegration::authenticators())
        // Anti-vacuity: the same route in a document that leaves it on.
        ->and($authentication->refusal(routeAuthenticationContext([CheckAbilities::class.':read'])))->not->toBeNull();
});

it('lists the framework authenticators and nothing else', function (): void {
    expect(array_keys(routeAuthenticationTable()))->toBe([Authenticate::class, AuthenticateWithBasicAuth::class]);
});

it('leaves out auth.session, which lets a guest through', function (): void {
    expect(guestRefusal(app(AuthenticateSession::class)))->toBeNull()
        ->and(app(RouteAuthentication::class)->refusal(routeAuthenticationContext(['auth.session'])))->toBeNull();
});

it('answers every spelling of an authenticator with its exception', function (string $middleware, string $exception): void {
    expect(app(RouteAuthentication::class)->refusal(routeAuthenticationContext([$middleware])))->toBe($exception);
})->with([
    'auth' => ['auth', 'Illuminate\\Auth\\AuthenticationException'],
    'auth:sanctum' => ['auth:sanctum', 'Illuminate\\Auth\\AuthenticationException'],
    'Authenticate::using()' => [Authenticate::using('api'), 'Illuminate\\Auth\\AuthenticationException'],
    "the application's own subclass by class name" => [ApplicationAuthenticate::class, 'Illuminate\\Auth\\AuthenticationException'],
    'auth.basic' => ['auth.basic', 'Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException'],
    'AuthenticateWithBasicAuth by class' => [AuthenticateWithBasicAuth::class, 'Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException'],
]);

it('answers for the first authenticator the route runs', function (): void {
    expect(app(RouteAuthentication::class)->refusal(routeAuthenticationContext(['throttle:60,1', 'auth.basic', 'auth'])))
        ->toBe('Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException');
});

it('counts middleware the configured pattern names as an authenticator of its own', function (): void {
    $authentication = app(RouteAuthentication::class);

    expect($authentication->refusal(routeAuthenticationContext(['jwt:access'], 'jwt*')))->toBe('Illuminate\\Auth\\AuthenticationException')
        ->and($authentication->refusesGuests(routeAuthenticationContext(['jwt:access'], 'jwt*')))->toBeTrue()
        // Anti-vacuity: the same route with no pattern runs nothing this can name.
        ->and($authentication->refusal(routeAuthenticationContext(['jwt:access'])))->toBeNull()
        ->and($authentication->refusesGuests(routeAuthenticationContext(['jwt:access'])))->toBeFalse();
});

it('finds no authenticator on a route that runs none', function (array $middleware): void {
    expect(app(RouteAuthentication::class)->refusal(routeAuthenticationContext($middleware, 'auth*')))->toBeNull();
})->with([
    'no middleware' => [[]],
    'throttle and can' => [['throttle:60,1', 'can:view,post']],
    'an alias outside the pattern' => [['jwt:access']],
]);
