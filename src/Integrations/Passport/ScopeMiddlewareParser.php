<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * Extracts the OAuth scopes a route requires from its Passport scope middleware. Passport registers
 * `scope` (any-of) and `scopes` (all-of); both take a comma-separated list and both ship a `::using()`
 * helper that renders the middleware as its class FQCN. The client-credentials middleware carry scopes
 * the same way — `client` (all-of) — so machine-to-machine routes get scopes too.
 *
 * Passport 13 renamed every one of those classes (`CheckScopes` → `CheckToken`, `CheckForAnyScope` →
 * `CheckTokenForAnyScope`, `CheckClientCredentials` → `EnsureClientIsResourceOwner`, and it dropped the
 * any-scope client variant). The aliases did not change, so both generations of FQCN are matched and an
 * app on either major is read correctly.
 *
 * All-of and any-of are kept apart in {@see ScopeRequirements} so the security requirement models each
 * correctly. Pure, so the middleware map is dataset-testable.
 */
final class ScopeMiddlewareParser
{
    /** Passport 12 and 13 spellings of the client-credentials middleware. */
    private const CLIENT_CREDENTIALS_FQCNS = [
        'Laravel\\Passport\\Http\\Middleware\\CheckClientCredentials',
        'Laravel\\Passport\\Http\\Middleware\\CheckClientCredentialsForAnyScope',
        'Laravel\\Passport\\Http\\Middleware\\EnsureClientIsResourceOwner',
    ];

    /**
     * All-of prefixes. Client-credentials belongs here: it validates every listed scope.
     *
     * @var list<string>
     */
    private const ALL_OF = [
        'scopes:',
        'Laravel\\Passport\\Http\\Middleware\\CheckScopes:',
        'Laravel\\Passport\\Http\\Middleware\\CheckToken:',
        'client:',
        'Laravel\\Passport\\Http\\Middleware\\CheckClientCredentials:',
        'Laravel\\Passport\\Http\\Middleware\\EnsureClientIsResourceOwner:',
    ];

    /**
     * Any-of prefixes.
     *
     * @var list<string>
     */
    private const ANY_OF = [
        'scope:',
        'Laravel\\Passport\\Http\\Middleware\\CheckForAnyScope:',
        'Laravel\\Passport\\Http\\Middleware\\CheckTokenForAnyScope:',
        'Laravel\\Passport\\Http\\Middleware\\CheckClientCredentialsForAnyScope:',
    ];

    /**
     * Any Passport client-credentials middleware, including the bare `client` alias and a parameter-less
     * FQCN — those protect the route without naming a scope.
     *
     * @param  list<string>  $middleware
     */
    public function hasClientCredentials(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if ($entry === 'client' || str_starts_with($entry, 'client:')) {
                return true;
            }
            foreach (self::CLIENT_CREDENTIALS_FQCNS as $fqcn) {
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
