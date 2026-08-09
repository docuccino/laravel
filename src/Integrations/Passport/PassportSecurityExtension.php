<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\AuthGuardDrivers;
use Illuminate\Contracts\Config\Repository;

/**
 * Auto-configures Passport OAuth2 security (design §Phase 4 — Passport auto-config): on a route
 * Passport protects it registers the `oauth2` scheme and sets the operation's `security` requirement,
 * with the per-operation scopes recovered from the scope middleware. A route counts as
 * Passport-protected when it carries `scope:`/`scopes:` or client-credentials middleware, OR when any
 * `auth:<guard>` (bare `auth` → the default guard) resolves to a `passport`-DRIVER guard via
 * `config('auth.guards')` (auth audit #8 — so a custom passport-driver guard is recognised, an `api`
 * guard on a token driver is not, and multi-guard lists work). Deferred when config already declares
 * security schemes, and skipped for `#[Unauthenticated]`. Class_exists-guarded on
 * `Laravel\Passport\Passport`.
 */
final class PassportSecurityExtension implements OperationExtension
{
    public function __construct(
        private readonly Repository $config,
        private readonly PassportRuntime $runtime = new PassportRuntime,
        private readonly ScopeMiddlewareParser $scopes = new ScopeMiddlewareParser,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Security;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->document->securitySchemes() !== []) {
            return;
        }

        if ($context->attributes->has(Unauthenticated::class)) {
            return;
        }

        $middleware = $context->route->middleware;
        $requirements = $this->scopes->parse($middleware);

        if (! $this->protects($middleware, $requirements)) {
            return;
        }

        $scheme = OAuth2Scheme::passport(
            $this->baseUrl($context),
            $this->path(),
            $this->scopeCatalogue($requirements),
            $this->runtime->passwordGrant,
            $this->runtime->implicitGrant,
        );

        $name = $context->components->registerSecurityScheme('passport', $scheme);

        $operation->setSecurity($requirements->toSecurity($name), Contribution::integration('passport', $context->actionSource()));
    }

    /**
     * @param  list<string>  $middleware
     */
    private function protects(array $middleware, ScopeRequirements $requirements): bool
    {
        // Scope or client-credentials scopes recovered → Passport-protected (driver-independent).
        if (! $requirements->isEmpty()) {
            return true;
        }

        // Bare `client` / parameter-less client-credentials FQCN protect without naming a scope.
        if ($this->scopes->hasClientCredentials($middleware)) {
            return true;
        }

        // A guard whose configured driver is `passport` (any name; multi-guard lists included).
        $drivers = AuthGuardDrivers::driversFor(
            $middleware,
            AuthGuardDrivers::map($this->config->get('auth.guards')),
            $this->defaultGuard(),
        );

        return in_array('passport', $drivers, true);
    }

    /**
     * The app's default auth guard (`config('auth.defaults.guard')`), for resolving bare `auth`.
     */
    private function defaultGuard(): string
    {
        return AuthGuardDrivers::defaultGuard($this->config->get('auth.defaults.guard'));
    }

    /**
     * The oauth2 flow scope map: the app's real Passport scope catalogue (`Passport::tokensCan()`),
     * augmented with any scope this route references that the catalogue is missing (so the security
     * requirement stays OAS-valid even in apps that never called `tokensCan()`). Missing scopes get
     * their id as description — the honest floor when no catalogue entry exists.
     *
     * @return array<string, string>
     */
    private function scopeCatalogue(ScopeRequirements $requirements): array
    {
        $catalogue = $this->runtime->scopes;

        foreach ($requirements->all() as $scope) {
            if (! array_key_exists($scope, $catalogue)) {
                $catalogue[$scope] = $scope;
            }
        }

        return $catalogue;
    }

    private function path(): string
    {
        $path = $this->config->get('passport.path');

        return is_string($path) && $path !== '' ? $path : 'oauth';
    }

    private function baseUrl(RouteContext $context): string
    {
        $configured = $context->document->integration('passport')['url'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $appUrl = $this->config->get('app.url');

        return is_string($appUrl) ? $appUrl : 'http://localhost';
    }
}
