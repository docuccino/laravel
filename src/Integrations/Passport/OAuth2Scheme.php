<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * The OAS `oauth2` scheme for a Passport-protected API: the always-available authorization-code and
 * client-credentials grants, plus password and implicit only when the app called
 * `Passport::enablePasswordGrant()`/`enableImplicitGrant()`, mapped to flows over the
 * `config('passport.path')` endpoints.
 *
 * The flow `scopes` map carries the app's real `Passport::tokensCan()` catalogue, because OAS 3.x requires
 * every scope a security requirement references to be defined here. A bare `*` stands in only when the app
 * defines no scopes at all. Pure, so the shape is dataset-testable.
 */
final class OAuth2Scheme
{
    /**
     * @param  array<string, string>  $scopes  Scope id → description (empty ⇒ conventional `*`).
     * @return array<string, mixed>
     */
    public static function passport(
        string $baseUrl,
        string $path = 'oauth',
        array $scopes = [],
        bool $passwordGrant = false,
        bool $implicitGrant = false,
    ): array {
        $base = rtrim($baseUrl, '/').'/'.trim($path, '/');
        $authorize = $base.'/authorize';
        $token = $base.'/token';
        $scopeMap = $scopes === [] ? ['*' => 'Full access to the API'] : $scopes;

        $flows = [
            'authorizationCode' => [
                'authorizationUrl' => $authorize,
                'tokenUrl' => $token,
                'refreshUrl' => $token,
                'scopes' => $scopeMap,
            ],
            'clientCredentials' => [
                'tokenUrl' => $token,
                'refreshUrl' => $token,
                'scopes' => $scopeMap,
            ],
        ];

        if ($implicitGrant) {
            $flows['implicit'] = [
                'authorizationUrl' => $authorize,
                'scopes' => $scopeMap,
            ];
        }

        if ($passwordGrant) {
            $flows['password'] = [
                'tokenUrl' => $token,
                'refreshUrl' => $token,
                'scopes' => $scopeMap,
            ];
        }

        return [
            'type' => 'oauth2',
            'flows' => $flows,
        ];
    }
}
