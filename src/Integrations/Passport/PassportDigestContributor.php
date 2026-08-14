<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Feeds the booted-app state that shapes Passport output into the environment digest (design §10): `app.url`
 * and `passport.path`, the two halves of every emitted oauth2 flow URL, the `Passport::tokensCan()`
 * catalogue, and whether the password/implicit grants were opted into. Runtime facts arrive pre-read via
 * {@see PassportRuntime}, keeping this integration free of vendor imports.
 */
final class PassportDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly PassportRuntime $runtime,
    ) {}

    public function digest(): string
    {
        $url = $this->config->get('app.url');
        $appUrl = is_string($url) ? $url : '';

        $path = $this->config->get('passport.path');

        $scopes = $this->runtime->scopes;
        ksort($scopes);
        $records = [];
        foreach ($scopes as $id => $description) {
            $records[] = $id.'=>'.$description;
        }

        return implode('|', [
            'appurl:'.$appUrl,
            'path:'.(is_string($path) ? $path : ''),
            'scopes:'.implode(',', $records),
            'grants:'.($this->runtime->passwordGrant ? 'password' : '').($this->runtime->implicitGrant ? 'implicit' : ''),
        ]);
    }
}
