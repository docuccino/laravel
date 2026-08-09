<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * Extracts the OAuth scopes a route requires from its Passport scope middleware (design §Phase 4 —
 * Passport per-operation scopes). Passport registers `scope`/`CheckForAnyScope` (ANY-of) and
 * `scopes`/`CheckScopes` (ALL-of); both take a comma-separated scope list and both ship a
 * `::using()` helper that renders the middleware as its class FQCN. The client-credentials
 * middleware carry scopes the same way — `client`/`CheckClientCredentials` (ALL-of) and
 * `CheckClientCredentialsForAnyScope` (ANY-of) — so machine-to-machine routes get per-operation
 * scopes too (auth audit #7). All-of scopes and any-of scopes are kept apart in a
 * {@see ScopeRequirements} so the security requirement can model each correctly. Pure so the
 * middleware map is dataset-testable.
 */
final class ScopeMiddlewareParser
{
    private const CHECK_CLIENT_CREDENTIALS = 'Laravel\\Passport\\Http\\Middleware\\CheckClientCredentials';

    private const CHECK_CLIENT_CREDENTIALS_FOR_ANY_SCOPE = 'Laravel\\Passport\\Http\\Middleware\\CheckClientCredentialsForAnyScope';

    /**
     * All-of prefixes: `scopes:` + `CheckScopes::using()` FQCN, plus the `client:` alias and
     * `CheckClientCredentials::using()` FQCN (client-credentials validates every listed scope).
     *
     * @var list<string>
     */
    private const ALL_OF = [
        'scopes:',
        'Laravel\\Passport\\Http\\Middleware\\CheckScopes:',
        'client:',
        self::CHECK_CLIENT_CREDENTIALS.':',
    ];

    /**
     * Any-of prefixes: `scope:` + `CheckForAnyScope::using()` FQCN, plus the
     * `CheckClientCredentialsForAnyScope::using()` FQCN.
     *
     * @var list<string>
     */
    private const ANY_OF = [
        'scope:',
        'Laravel\\Passport\\Http\\Middleware\\CheckForAnyScope:',
        self::CHECK_CLIENT_CREDENTIALS_FOR_ANY_SCOPE.':',
    ];

    /**
     * Whether the route carries any Passport client-credentials middleware — including the bare
     * `client` alias or a parameter-less FQCN, which protect the route without naming a scope
     * (auth audit #7).
     *
     * @param  list<string>  $middleware
     */
    public function hasClientCredentials(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if ($entry === 'client' || str_starts_with($entry, 'client:')) {
                return true;
            }
            foreach ([self::CHECK_CLIENT_CREDENTIALS, self::CHECK_CLIENT_CREDENTIALS_FOR_ANY_SCOPE] as $fqcn) {
                if ($entry === $fqcn || str_starts_with($entry, $fqcn.':')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $middleware
     */
    public function parse(array $middleware): ScopeRequirements
    {
        $allOf = [];
        $anyOf = [];

        foreach ($middleware as $entry) {
            foreach ($this->scopesFor($entry, self::ALL_OF) as $scope) {
                if ($scope !== '' && ! in_array($scope, $allOf, true)) {
                    $allOf[] = $scope;
                }
            }
            foreach ($this->scopesFor($entry, self::ANY_OF) as $scope) {
                if ($scope !== '' && ! in_array($scope, $anyOf, true)) {
                    $anyOf[] = $scope;
                }
            }
        }

        return new ScopeRequirements($allOf, $anyOf);
    }

    /**
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    private function scopesFor(string $middleware, array $prefixes): array
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($middleware, $prefix)) {
                return array_map('trim', explode(',', substr($middleware, strlen($prefix))));
            }
        }

        return [];
    }
}
