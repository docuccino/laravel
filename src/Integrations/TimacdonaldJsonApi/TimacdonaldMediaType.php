<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

use Docuccino\Core\Extensions\Contracts\PayloadMediaTypeResolver;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\ApiResources\ResourceMediaType;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;

/**
 * A timacdonald resource, or an anonymous collection of one, serialises as `application/vnd.api+json`.
 * Contributed only while the integration is enabled, so a disabled one never flips the media type. The
 * anonymous-collection check is the shared Illuminate one from the ApiResources reflector.
 */
final class TimacdonaldMediaType implements PayloadMediaTypeResolver
{
    public function mediaTypeFor(DType $payload): ?string
    {
        if (! $payload instanceof ClassT) {
            return null;
        }

        if (TimacdonaldResourceReflector::isResource($payload->fqcn)) {
            return ResourceMediaType::JSON_API;
        }

        if (! ResourceReflector::isAnonymousCollection($payload->fqcn)) {
            return null;
        }

        $item = $payload->typeArgs[0] ?? null;

        return $item instanceof ClassT && TimacdonaldResourceReflector::isResource($item->fqcn) ? ResourceMediaType::JSON_API : null;
    }
}
