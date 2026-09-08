<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Support\PlainText;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Throwable;

/**
 * The two maps a route's middleware is read against — the alias map ({@see MiddlewareAliases}) and the
 * group map — taken off the router once the router has been given them.
 *
 * It has to be given them, because neither is there to begin with. Both are written to the router by
 * `Illuminate\Foundation\Http\Kernel::__construct()`, through `syncMiddlewareToRouter()`, and an
 * application's own groups reach that kernel later still: on the `Middleware` configuration object the
 * framework applies when the kernel RESOLVES. A documentation build runs from the console, which
 * resolves no HTTP kernel — measured on a stock Laravel 12 application booted through its console
 * kernel, every route is present and there are no aliases and no groups at all. Nothing a route
 * inherits is visible then: a group's `throttle` publishes no 429 and no rate-limit headers, a group's
 * authenticator publishes no 401 and no security requirement, and a protected route goes out documented
 * as PUBLIC. That is a confident false claim rather than a vague one, which is why this is not left to
 * whatever the router happens to hold.
 *
 * So the kernel is resolved once — for its effect on the router, not for anything it answers — and both
 * maps are then read off the ROUTER, which is also where a service provider's own
 * `Route::middlewareGroup()` and `Route::aliasMiddleware()` land.
 *
 * What that resolution runs is bounded, and worth stating because a documentation build executing
 * application code is a cost rather than a convenience. Two things: the kernel's constructor, which
 * assigns two properties and writes to the router, and the container's after-resolving callbacks for
 * the contract — in a stock application exactly one, the framework's own, which applies
 * `bootstrap/app.php`'s middleware block. The framework already invokes that same block once per
 * console command (its `afterResolving(ConsoleKernel)` hook calls it and throws the result away), so
 * this makes a second invocation of code that already runs, in a process that then exits. Nothing here
 * bootstraps the application or handles a request: `Kernel::bootstrap()` is reached from `handle()`,
 * which is never called.
 *
 * A resolution that fails degrades to whatever the router holds and says so ({@see unreadable()}) — it
 * is never allowed to take the build with it, and it is never allowed to go unsaid.
 */
final class MiddlewareRegistrations
{
    private bool $attempted = false;

    private ?Diagnostic $failure = null;

    public function __construct(
        private readonly Router $router,
        // Null leaves the router exactly as it is, which is what a resolver built by hand rather than by
        // the container gets: there is nothing to resolve the kernel through and so nothing to report.
        private readonly ?Container $app = null,
    ) {}

    /**
     * Both maps, filling the router first. `$report` hears what could not be read — on every call
     * rather than only the first, because a build with two documents owes each of them the warning.
     *
     * @param  ?callable(Diagnostic): void  $report
     * @return array{aliases: array<string, string>, groups: array<array-key, mixed>}
     */
    public function read(?callable $report = null): array
    {
        $this->fill();

        if ($this->failure !== null && $report !== null) {
            $report($this->failure);
        }

        return [
            'aliases' => MiddlewareAliases::of($this->router, $report),
            'groups' => $this->router->getMiddlewareGroups(),
        ];
    }

    /** Resolve the HTTP kernel once, for the registrations its construction writes to the router. */
    private function fill(): void
    {
        if ($this->attempted || $this->app === null) {
            return;
        }

        $this->attempted = true;

        // Already resolved — the router has its registrations and there is nothing left to run. Asked
        // rather than resolved again, so a build performed where something else already built the kernel
        // triggers no application code at all.
        if ($this->app->resolved(Kernel::class)) {
            return;
        }

        try {
            $this->app->make(Kernel::class);
        } catch (Throwable $failure) {
            $this->failure = self::unreadable(PlainText::of($failure->getMessage()));
        }
    }

    /**
     * The registrations could not be filled. Named for what the document loses rather than for the
     * container call that failed, because the reader's problem is a route published as public.
     */
    private static function unreadable(string $reason): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'route.middleware-registrations-unreadable',
            message: sprintf(
                'Could not read this application\'s middleware registrations: resolving %s failed with: %s. '
                .'Middleware groups, and the aliases an application registers itself, are written to the router '
                .'when that kernel is constructed — so nothing a route INHERITS from a group is visible to this '
                .'build. A group\'s throttle publishes no 429 and no rate-limit headers, and a group\'s '
                .'authenticator publishes no 401 and no security requirement, which documents a protected route '
                .'as public. Middleware written on the route itself is unaffected. Whatever makes that kernel '
                .'unresolvable has to be settled before the document describes what a request will meet.',
                Kernel::class,
                $reason,
            ),
        );
    }
}
