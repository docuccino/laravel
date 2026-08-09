<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

/**
 * The OAS security schemes for Sanctum's two auth modes, which apps commonly run on the same routes:
 * external API tokens as http bearer, and first-party SPA session auth as an apiKey-in-cookie. OAS can't
 * structurally model the XSRF/CSRF handshake, so the stateful scheme spells it out in its `description`.
 * The names are stable so an operation's OR-list `security` can reference them.
 */
final class SanctumScheme
{
    public const TOKEN = 'sanctumToken';

    public const STATEFUL = 'sanctumStateful';

    /**
     * @return array<string, mixed>
     */
    public static function token(): array
    {
        return [
            'type' => 'http',
            'scheme' => 'bearer',
            'description' => 'Sanctum API token authentication: send a personal access / API token as a `Bearer` token in the `Authorization` header.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stateful(string $cookieName): array
    {
        return [
            'type' => 'apiKey',
            'in' => 'cookie',
            'name' => $cookieName,
            'description' => 'Sanctum first-party (SPA) session authentication: authenticate via the login route to receive the '
                .'`'.$cookieName.'` session cookie, then send the `X-XSRF-TOKEN` header (read from the `XSRF-TOKEN` cookie) on '
                .'state-changing requests. OpenAPI cannot fully model the CSRF handshake, so it is described here.',
        ];
    }
}
