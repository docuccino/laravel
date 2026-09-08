<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Support\MiddlewareRegistrations;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Router;

/**
 * Where the two middleware maps come from. A router holds neither until the HTTP kernel is constructed,
 * and a documentation build constructs none, so the maps are read after the kernel has been resolved
 * for that effect — what that resolution runs, and what it costs, is stated in
 * {@see MiddlewareRegistrations}.
 */
it('fills the router with the registrations a console build would otherwise never see', function (): void {
    refreshWithoutHttpKernel();
    registerAppMiddlewareGroup('docuccino-tenant', ['auth:web', 'throttle:60,1']);

    // The premise, stated from the framework: an application's own group is nowhere yet.
    expect(app('router')->getMiddlewareGroups())->toBe([]);

    $read = (new MiddlewareRegistrations(app('router'), app()))->read();

    expect($read['groups'])->toHaveKey('docuccino-tenant')
        ->and($read['groups']['docuccino-tenant'])->toBe(['auth:web', 'throttle:60,1'])
        // The framework's own groups arrive with it, and so does the alias map the same kernel writes.
        ->and($read['groups'])->toHaveKeys(['web', 'api'])
        ->and($read['aliases'])->toHaveKey('auth');
});

/**
 * Once per build, and not at all where something else has already done it — a documentation build
 * running an application's `bootstrap/app.php` middleware block is a cost, so the number of times it
 * runs is a fact worth pinning rather than describing. Counted by executing it, because a skip nothing
 * has ever been made to take is a claim rather than a guard.
 */
it('resolves the kernel once, and not at all where something else already has', function (): void {
    refreshWithoutHttpKernel();

    $runs = 0;
    app()->afterResolving(HttpKernelContract::class, function () use (&$runs): void {
        $runs++;
    });

    $registrations = new MiddlewareRegistrations(app('router'), app());
    $registrations->read();
    expect($runs)->toBe(1);

    // A second document off the same reader.
    $registrations->read();
    expect($runs)->toBe(1);

    // And a reader built after the fact, which finds the kernel resolved and runs nothing.
    (new MiddlewareRegistrations(app('router'), app()))->read();
    expect($runs)->toBe(1);
});

/**
 * The kernel could not be resolved: the answer degrades to whatever the router holds — never nothing,
 * never an exception — and says so, because a route published with no middleware because the groups
 * could not be read is a confident false claim rather than a vague one. Provoked rather than asserted,
 * in each of the two shapes a container can fail in.
 */
it('degrades to the registrations the router holds and says so when the kernel cannot be resolved', function (bool $throwing, string $expected): void {
    refreshWithoutHttpKernel();
    registerAppMiddlewareGroup('docuccino-tenant', ['auth:web']);

    if ($throwing) {
        app()->bind(HttpKernelContract::class, static fn () => throw new RuntimeException('the kernel would not build'));
    } else {
        // Bound to the contract itself, which is the shape an application that dropped the binding leaves.
        app()->bind(HttpKernelContract::class, HttpKernelContract::class);
    }

    $reported = [];
    $registrations = new MiddlewareRegistrations(app('router'), app());
    $read = $registrations->read(function (Diagnostic $diagnostic) use (&$reported): void {
        $reported[] = $diagnostic;
    });

    expect($read['groups'])->toBe([])
        // The alias half still has the framework's own table under it, which is all that read ever had.
        ->and($read['aliases'])->toBe((new Middleware)->getMiddlewareAliases())
        ->and($reported)->toHaveCount(1)
        ->and($reported[0]->code)->toBe('route.middleware-registrations-unreadable')
        ->and($reported[0]->severity)->toBe(Severity::Warning)
        ->and($reported[0]->message)->toContain($expected);

    // A build with two documents owes each of them the warning: a document short one is a document
    // whose reader is not told its authentication may be missing.
    $registrations->read(function (Diagnostic $diagnostic) use (&$reported): void {
        $reported[] = $diagnostic;
    });
    expect($reported)->toHaveCount(2);
})->with([
    'the kernel construction throws' => [true, 'the kernel would not build'],
    'the contract resolves to nothing instantiable' => [false, 'not instantiable'],
]);

/**
 * A reader built by hand rather than by the container has nothing to resolve the kernel through, so it
 * leaves the router exactly as it found it — and reports nothing, because nothing was attempted and a
 * warning about a container that was never there names no problem its reader could act on.
 */
it('leaves the router alone and reports nothing where there is no container', function (): void {
    $router = new Router(new Dispatcher);

    $reported = [];
    $read = (new MiddlewareRegistrations($router))->read(function (Diagnostic $diagnostic) use (&$reported): void {
        $reported[] = $diagnostic;
    });

    expect($read['groups'])->toBe([])
        ->and($read['aliases'])->toBe((new Middleware)->getMiddlewareAliases())
        ->and($reported)->toBe([]);
});
