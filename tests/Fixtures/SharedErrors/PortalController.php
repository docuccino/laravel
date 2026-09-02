<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Docuccino\Attributes\Example;

/**
 * Three endpoints behind the same authentication, failing it for the reasons
 * {@see PortalProblemRenderer} answers — and one of them documenting the 401 with an example of its own.
 */
final class PortalController
{
    /** @return array{ok: bool} */
    public function dashboard(): array
    {
        return ['ok' => true];
    }

    /** @return array{ok: bool} */
    public function exports(): array
    {
        return ['ok' => true];
    }

    /** @return array{ok: bool} */
    #[Example(
        value: [
            'type' => 'https://example.com/problems/portal-token-revoked',
            'title' => 'Unauthenticated',
            'status' => 401,
            'detail' => 'The access token for this portal was revoked.',
        ],
        status: 401,
        mediaType: 'application/problem+json',
    )]
    public function reports(): array
    {
        return ['ok' => true];
    }
}
