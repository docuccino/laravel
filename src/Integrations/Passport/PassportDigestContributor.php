<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Contributes Passport's output-shaping booted-app state to the environment digest (design §10, A4):
 * `app.url` (feeds the oauth2 flow URLs into operation security), the scope catalogue
 * (`Passport::tokensCan()`), and whether the password / implicit grants were opted into. The runtime
 * facts arrive pre-read via {@see PassportRuntime} (the integration stays vendor-import-free — the
 * service provider reads the vendor class and injects them). Gated with the Passport integration.
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

        $scopes = $this->runtime->scopes;
        ksort($scopes);
        $records = [];
        foreach ($scopes as $id => $description) {
            $records[] = $id.'=>'.$description;
        }

        return implode('|', [
            'appurl:'.$appUrl,
            'scopes:'.implode(',', $records),
            'grants:'.($this->runtime->passwordGrant ? 'password' : '').($this->runtime->implicitGrant ? 'implicit' : ''),
        ]);
    }
}
