<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\PayloadMediaTypeResolver;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;

/**
 * The gated {@see PayloadMediaTypeResolver} for Laravel first-party JSON:API resources: a payload that
 * is a JSON:API resource, or an anonymous collection of one, serialises as `application/vnd.api+json`.
 * Contributed only when `api_resources` is enabled, so a disabled integration never flips the media
 * type. A plain (non-JSON:API) resource defers (null → the default `application/json`).
 */
final class ResourceMediaType implements PayloadMediaTypeResolver
{
    public const JSON_API = 'application/vnd.api+json';

    public function mediaTypeFor(DType $payload): ?string
    {
        if (! $payload instanceof ClassT) {
            return null;
        }

        if (ResourceReflector::isJsonApiResource($payload->fqcn)) {
            return self::JSON_API;
        }

        if (! ResourceReflector::isAnonymousCollection($payload->fqcn)) {
            return null;
        }

        $item = $payload->typeArgs[0] ?? null;

        return $item instanceof ClassT && ResourceReflector::isJsonApiResource($item->fqcn) ? self::JSON_API : null;
    }
}
