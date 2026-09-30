<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Docuccino\Laravel\Integrations\Support\JsonApiTopLevel;
use Illuminate\Container\Container;

/**
 * Whether `JsonApiResource::resolveServerImplementationUsing()` bound a callback at boot, which decides
 * whether every timacdonald document publishes a `jsonapi` member ({@see JsonApiTopLevel}). The callback
 * itself is only ever run per request, so being bound is all a build reads of it.
 */
final class ServerImplementationDigestContributor implements EnvironmentDigestContributor
{
    public function digest(): string
    {
        return implode("\0", ['server-implementation', Container::getInstance()->bound(TimacdonaldResourceReflector::SERVER_IMPLEMENTATION_RESOLVER) ? 'bound' : '']);
    }
}
