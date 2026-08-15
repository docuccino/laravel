<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Feeds the one piece of booted-app state that shapes Sanctum output and belongs to nobody else: the
 * app's `session.cookie` name, since the stateful cookie IS the session cookie. It is read at handle
 * time and reflects no route file, so it has to key the warm-fragment cache.
 *
 * The guard → driver map this extension also reads is covered by the adapter's unconditional auth-config contributor,
 * which runs whether or not Sanctum is installed — a second copy here would key it twice for a Sanctum
 * app and not at all for anyone else's.
 */
final class SanctumDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function digest(): string
    {
        $cookie = $this->config->get('session.cookie');

        return 'session-cookie:'.(is_string($cookie) ? $cookie : '');
    }
}
