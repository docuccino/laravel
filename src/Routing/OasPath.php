<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

/**
 * The one rule for turning a Laravel route URI into the path an OAS document publishes: always
 * leading-slashed, and an optional parameter's `?` marker dropped — OpenAPI says a path parameter is
 * required, so `{form?}` and `{form}` are the same template.
 *
 * One owner because two readers need the same answer: the generator, which mints the path, and the
 * lookup behind `docuccino:explain`, which has to find that path again from the router.
 */
final class OasPath
{
    public static function of(string $uri): string
    {
        $path = '/'.ltrim($uri, '/');

        return preg_replace('/\{([^}]+)\?}/', '{$1}', $path) ?? $path;
    }
}
