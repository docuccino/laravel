<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Closure;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;

/**
 * Whether a route refuses a request that carries no credentials, and with which exception. Read from the
 * middleware the route actually runs (groups expanded, `withoutMiddleware()` subtracted), by class through
 * the build's alias map, so an alias of the application's own or a subclass of the framework's
 * authenticator counts and an alias re-pointed elsewhere does not ({@see MiddlewareClasses}).
 *
 * The framework's authenticators refuse whatever the document's configuration says, so this does not
 * depend on `security.auth_middleware`. That pattern still counts where it matches: it is how an author
 * names an authenticator of their own ({@see AuthMiddlewareDetector}). A package's own middleware counts
 * where its integration is enabled for the document, which names each class that reads the user or token
 * itself and what it throws; which classes those are depends on the package's installed version, so the
 * integration owns the list. Such a class refuses a guest with a 401 (`refusal`), or with the same 403 it
 * gives a user lacking what the entry names (a null `refusal`), and `signal` names it in provenance.
 *
 * @phpstan-type Authenticators array<class-string, array{refusal: class-string|null, denial: class-string, signal: string}>
 */
final class RouteAuthentication
{
    /**
     * Each framework middleware that refuses a guest, and what it throws. `auth.basic` fails through the
     * guard's `basic()`, which throws the HTTP exception rather than `AuthenticationException`.
     * `auth.session` is not here: it passes a guest through.
     */
    private const REFUSALS = [
        Authenticate::class => 'Illuminate\\Auth\\AuthenticationException',
        AuthenticateWithBasicAuth::class => 'Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException',
    ];

    /** What an authenticator the configured pattern names is taken to throw. */
    private const PATTERN_REFUSAL = 'Illuminate\\Auth\\AuthenticationException';

    /** @var Closure(DocumentConfig): Authenticators */
    private readonly Closure $authenticators;

    /**
     * @param  (Closure(DocumentConfig): Authenticators)|null  $authenticators  the middleware the document's
     *                                                                          enabled integrations name: what
     *                                                                          each throws at a guest, and at a
     *                                                                          user or token lacking what the
     *                                                                          entry names
     */
    public function __construct(private readonly MiddlewareClasses $middleware, ?Closure $authenticators = null)
    {
        $this->authenticators = $authenticators ?? static fn (DocumentConfig $document): array => [];
    }

    /**
     * The exception the route's first authenticator throws for a request without credentials, or null
     * where nothing on the route refuses one.
     */
    public function refusal(RouteContext $context): ?string
    {
        $refusals = self::REFUSALS;
        foreach (($this->authenticators)($context->document) as $class => $thrown) {
            if ($thrown['refusal'] !== null) {
                $refusals[$class] = $thrown['refusal'];
            }
        }

        foreach ($context->route->middleware as $middleware) {
            foreach ($refusals as $class => $exception) {
                if ($this->middleware->runs($context, $middleware, $class)) {
                    return $exception;
                }
            }

            if (AuthMiddlewareDetector::matchesEntry($context, $middleware, $this->middleware)) {
                return self::PATTERN_REFUSAL;
            }
        }

        return null;
    }

    /**
     * The guard arguments of an entry running the framework's authenticator ({@see
     * AuthMiddlewareNames::guardArguments()}), whatever alias the application named it under: they are
     * the middleware's parameters, so the entry is also read as the alias map resolves it.
     */
    public function guardArguments(RouteContext $context, string $middleware): ?string
    {
        return AuthMiddlewareNames::guardArguments($middleware)
            ?? AuthMiddlewareNames::guardArguments($this->middleware->resolve($context, $middleware));
    }

    /**
     * Whether a guest is refused before the action, and so before any gate on the route: by a 401, or by
     * middleware that refuses one with its 403.
     */
    public function refusesGuests(RouteContext $context): bool
    {
        if ($this->refusal($context) !== null) {
            return true;
        }

        $authenticators = ($this->authenticators)($context->document);
        foreach ($context->route->middleware as $middleware) {
            foreach (array_keys($authenticators) as $class) {
                if ($this->middleware->runs($context, $middleware, $class)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What one middleware entry throws at a user or token lacking what the entry names, with the signal
     * naming it, or null. An entry naming nothing checks the user or token and nothing past it.
     *
     * @return array{signal: string, exception: class-string}|null
     */
    public function denial(RouteContext $context, string $middleware): ?array
    {
        if (MiddlewareName::bare($middleware)) {
            return null;
        }

        foreach (($this->authenticators)($context->document) as $class => $thrown) {
            if ($this->middleware->runs($context, $middleware, $class)) {
                return ['signal' => $thrown['signal'], 'exception' => $thrown['denial']];
            }
        }

        return null;
    }
}
