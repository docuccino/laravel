<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

use Docuccino\Core\Extensions\Contracts\PayloadMediaTypeResolver;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\ApiResources\ResourceMediaType;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;

/**
 * The gated {@see PayloadMediaTypeResolver} for `timacdonald/json-api` resources: a payload that is a
 * timacdonald resource, or an anonymous collection of one, serialises as `application/vnd.api+json`.
 * Contributed only when `timacdonald_json_api` is enabled, so a disabled integration never flips the
 * media type. (The anonymous-collection predicate is the shared Illuminate one from the ApiResources
 * reflector — integration→integration reuse, allowed.)
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
