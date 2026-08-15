<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Feeds Laravel's own auth configuration — the guard → driver map and the default guard — into the
 * environment digest (design §10). It is what decides which security integration owns a route, it is
 * read at handle time, and it reflects no route file, so a warm fragment survives a change to it unless
 * something keys on it.
 *
 * Registered UNCONDITIONALLY, unlike the per-package security contributors, because auth config is the
 * framework's and not any one package's: every integration that reads it would otherwise have to carry
 * its own copy, and an app with only ONE of them installed would be covered only by accident of which
 * one that was. It lives beside {@see AuthGuardDrivers} for the same reason — the auth vocabulary is
 * shared, not owned by whichever package happens to be installed.
 */
final class AuthConfigDigestContributor implements EnvironmentDigestContributor
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

        return 'guards:'.implode(',', $records)
            .'|default-guard:'.AuthGuardDrivers::defaultGuard($this->config->get('auth.defaults.guard'));
    }
}
