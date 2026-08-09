<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Docuccino\Laravel\Integrations\Support\AuthGuardDrivers;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Feeds the booted-app state that shapes Sanctum output into the environment digest (design §10): the
 * configured guard → driver map, which decides whether a route resolves a Sanctum mode, and the app's
 * `session.cookie` name, since the stateful cookie is the session cookie. Both are read at handle time and
 * reflect no route file, so they have to key the warm-fragment cache. Guard map sorted by name.
 */
final class SanctumDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function digest(): string
    {
        $guards = AuthGuardDrivers::map($this->config->get('auth.guards'));
        ksort($guards);
        $records = [];
        foreach ($guards as $name => $driver) {
            $records[] = $name.'=>'.$driver;
        }

        $defaultGuard = $this->config->get('auth.defaults.guard');
        $cookie = $this->config->get('session.cookie');

        return implode('|', [
            'guards:'.implode(',', $records),
            'default-guard:'.(is_string($defaultGuard) ? $defaultGuard : ''),
            'session-cookie:'.(is_string($cookie) ? $cookie : ''),
        ]);
    }
}
