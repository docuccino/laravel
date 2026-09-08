<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Support\MiddlewareAliases;
use Docuccino\Laravel\Support\MiddlewareRegistrations;
use Docuccino\Laravel\Support\MiddlewareResolution;
use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Docuccino\Laravel\Tests\Fixtures\Middleware\MergesATenant;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Router;

/**
 * The floor under the alias map. A router carries none until the HTTP kernel is constructed, and a
 * build has that done before reading it ({@see MiddlewareRegistrations}); this is what is left where
 * that could not be performed — never nothing, and never narrower than the framework's own table.
 */
it('answers the framework\'s own default aliases for a router nothing has synced', function (): void {
    $bare = new Router(new Dispatcher);

    // The premise, stated from the framework: an unsynced router really does hold nothing.
    expect($bare->getMiddleware())->toBe([]);

    $aliases = MiddlewareAliases::of($bare);

    // Read against the source of truth rather than a copy of it, and asserted as the whole table so a
    // default the framework adds or renames cannot leave this short.
    expect($aliases)->toBe((new Middleware)->getMiddlewareAliases())
        ->and($aliases)->toHaveKey('auth')
        ->and($aliases['auth'])->toBe(Authenticate::class)
        // And what the fallback is FOR: without it the subtraction stops equating the two spellings of
        // one middleware wherever the map could not be filled, so a route that opts out of its
        // authenticator keeps a 401 it does not enforce.
        ->and(MiddlewareResolution::subtract(['auth:web'], [Authenticate::using('web')], $aliases))->toBe([]);
});

it('lets an alias the application registered win over the default it replaces', function (): void {
    $router = new Router(new Dispatcher);
    $router->aliasMiddleware('auth', ApplicationAuthenticate::class);
    $router->aliasMiddleware('tenant', MergesATenant::class);
    // A map is data, and `aliasMiddleware()` types neither half: an application can register a closure
    // under a name, and the middleware set is a set of strings. Dropped here, at the boundary, so no
    // reader downstream has to hold a type looser than the one it can act on.
    $router->aliasMiddleware('closure', fn () => null);

    $aliases = MiddlewareAliases::of($router);

    expect($aliases['auth'])->toBe(ApplicationAuthenticate::class)
        ->and($aliases)->toHaveKey('tenant')
        ->and($aliases)->not->toHaveKey('closure')
        // …and the defaults it did not touch are still there.
        ->and($aliases['can'])->toBe(Authorize::class);
});

/**
 * The framework's table is read off the version the application resolved, so every way that read can
 * fail is a way this build could die: a method a later major renamed, moved, made protected or made
 * static, a constructor it gave a required argument, a body that throws, an answer that is not a map.
 * Each degrades to whatever the router itself holds — never nothing, never an exception — and says so.
 * Executed against a stand-in for each shape rather than asserted, because a `class_exists` guard that
 * has never been made to fire is a claim rather than a guard.
 */
it('degrades to the aliases the router holds when the framework\'s table cannot be read', function (callable $table): void {
    $router = new Router(new Dispatcher);
    $router->aliasMiddleware('tenant', MergesATenant::class);

    $reported = [];
    $aliases = MiddlewareAliases::of($router, function (Diagnostic $diagnostic) use (&$reported): void {
        $reported[] = $diagnostic;
    }, $table());

    expect($aliases)->toBe(['tenant' => MergesATenant::class])
        ->and($reported)->toHaveCount(1)
        ->and($reported[0]->code)->toBe('route.middleware-aliases-unreadable')
        ->and($reported[0]->severity)->toBe(Severity::Warning);
})->with([
    'the method is not there' => [fn (): string => (new class {})::class],
    'the method is protected' => [fn (): string => (new class
    {
        /** @return array<string, string> */
        protected function getMiddlewareAliases(): array
        {
            return ['auth' => 'App\\Nope'];
        }
    })::class],
    'the method is static' => [fn (): string => (new class
    {
        /** @return array<string, string> */
        public static function getMiddlewareAliases(): array
        {
            return ['auth' => 'App\\Nope'];
        }
    })::class],
    'the constructor takes a required argument' => [fn (): string => (new class(1)
    {
        public function __construct(public int $version) {}

        /** @return array<string, string> */
        public function getMiddlewareAliases(): array
        {
            return ['auth' => 'App\\Nope'];
        }
    })::class],
    'the method throws' => [fn (): string => (new class
    {
        /** @return array<string, string> */
        public function getMiddlewareAliases(): array
        {
            throw new RuntimeException('no aliases here');
        }
    })::class],
    'the method answers with something that is not a map' => [fn (): string => (new class
    {
        public function getMiddlewareAliases(): string
        {
            return 'nope';
        }
    })::class],
]);

/** An application without `Illuminate\Foundation` has no default table to find, so there is nothing to report either. */
it('reports nothing where the framework configuration class is absent', function (): void {
    $router = new Router(new Dispatcher);
    $router->aliasMiddleware('tenant', MergesATenant::class);

    $reported = [];
    $aliases = MiddlewareAliases::of($router, function (Diagnostic $diagnostic) use (&$reported): void {
        $reported[] = $diagnostic;
    }, 'Illuminate\\Foundation\\Configuration\\NoSuchThing');

    expect($aliases)->toBe(['tenant' => MergesATenant::class])
        ->and($reported)->toBe([]);
});
