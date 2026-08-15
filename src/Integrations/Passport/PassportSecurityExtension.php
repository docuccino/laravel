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
use Docuccino\Laravel\Support\MachineDependentValue;
use Illuminate\Contracts\Config\Repository;

/**
 * Registers the `oauth2` scheme and the operation's `security` requirement on Passport-protected routes,
 * with per-operation scopes recovered from the scope middleware. A route counts as protected when it
 * carries `scope:`/`scopes:` or client-credentials middleware, or when an `auth:<guard>` (bare `auth` =
 * default guard) resolves to a guard whose `config('auth.guards')` driver is `passport` — so a custom
 * passport-driver guard is recognised, an `api` guard on the token driver is not, and multi-guard lists
 * work. Defers when config already declares security schemes; skips `#[Unauthenticated]`.
 */
final class PassportSecurityExtension implements OperationExtension
{
    /** What a machine-dependent-value report names, since the scheme's own slot isn't settled yet. */
    private const PUBLISHED = "The Passport scheme's OAuth2 flow URLs";

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
        // Recovered scopes mean Passport-protected regardless of driver.
        if (! $requirements->isEmpty()) {
            return true;
        }

        // Bare `client` / parameter-less client-credentials FQCN protects without naming a scope.
        if ($this->scopes->hasClientCredentials($middleware)) {
            return true;
        }

        // A guard of any name whose configured driver is `passport`; multi-guard lists included.
        $drivers = AuthGuardDrivers::driversFor(
            $middleware,
            AuthGuardDrivers::map($this->config->get('auth.guards')),
            $this->defaultGuard(),
        );

        return in_array('passport', $drivers, true);
    }

    /** The app's default auth guard, for resolving bare `auth`. */
    private function defaultGuard(): string
    {
        return AuthGuardDrivers::defaultGuard($this->config->get('auth.defaults.guard'));
    }

    /**
     * The app's real `Passport::tokensCan()` catalogue plus any scope this route references that the
     * catalogue lacks, so the security requirement stays OAS-valid in apps that never called
     * `tokensCan()`. Those extras get their id as description.
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

    /**
     * The base every flow URL hangs off: the document's own pin, else the app's `app.url`.
     *
     * The unpinned path is reported rather than trusted. Laravel's shipped `config/app.php` reads
     * `env('APP_URL', 'http://localhost')`, so an app that never set `APP_URL` hands us a perfectly
     * good STRING and the fallback below never fires — the document then tells every client to get its
     * tokens from the machine the build ran on. The URLs stay — OAS requires a `tokenUrl` on every
     * flow, and removing one is the worse defect — and a diagnostic says where they came from.
     */
    private function baseUrl(RouteContext $context): string
    {
        $configured = $context->document->integration('passport')['url'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $signature = $context->route->signature($context->httpMethod());
        $appUrl = $this->config->get('app.url');

        if (! is_string($appUrl) || $appUrl === '') {
            $context->components->addDiagnostic(MachineDependentValue::forDefault(
                self::PUBLISHED, 'http://localhost', 'app.url', 'integrations.passport.url', $signature,
            ));

            return 'http://localhost';
        }

        $report = MachineDependentValue::forUrl(
            self::PUBLISHED, $appUrl, 'app.url', 'integrations.passport.url', $signature,
        );

        if ($report !== null) {
            $context->components->addDiagnostic($report);
        }

        return $appUrl;
    }
}
