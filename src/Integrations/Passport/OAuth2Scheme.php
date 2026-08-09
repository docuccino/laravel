<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * The OAS `oauth2` security scheme for a Passport-protected API (design §Phase 4 — Passport auto-
 * config): Passport's authorization-code and client-credentials grants (always available) plus the
 * password and implicit grants ONLY when the app opted into them (`Passport::enablePasswordGrant()` /
 * `enableImplicitGrant()`), mapped to OAS flows over the `config('passport.path')` endpoints. The flow
 * `scopes` map carries the app's real scope catalogue (`Passport::tokensCan()`) so every scope an
 * operation's security requirement references is DEFINED here — an OAS 3.x validity requirement; a
 * bare-`*` fallback is used only when the app defines no scopes. Pure so the shape is dataset-testable.
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
