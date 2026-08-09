<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;

/**
 * Names the `timacdonald/json-api` resource classes by FQCN string (the package is optional, so we
 * never hard-reference its symbols) — the pre-13 JSON:API base Laravel 13's first-party resources
 * were upstreamed from. Mirrors {@see ResourceReflector}
 * for its own family: an `abstract JsonApiResource` (a subclass of Laravel's `JsonResource`) and its
 * own `JsonApiResourceCollection`, plus the helper the parameters extension uses to tell whether a
 * return type ultimately produces one of these documents.
 */
final class TimacdonaldResourceReflector
{
    public const JSON_API_RESOURCE = 'TiMacDonald\\JsonApi\\JsonApiResource';

    public const JSON_API_COLLECTION = 'TiMacDonald\\JsonApi\\JsonApiResourceCollection';

    /** Whether an FQCN is a `timacdonald/json-api` resource (guarded by `class_exists`). */
    public static function isResource(string $fqcn): bool
    {
        return class_exists(self::JSON_API_RESOURCE) && is_a($fqcn, self::JSON_API_RESOURCE, true);
    }

    /**
     * Whether a return type ultimately produces a timacdonald JSON:API document — the resource
     * itself or a collection whose item is one — so the `include`/`fields` params apply.
     */
    public static function involvesJsonApi(DType $type): bool
    {
        if (! $type instanceof ClassT) {
            return false;
        }

        if (self::isResource($type->fqcn)) {
            return true;
        }

        if (! is_a($type->fqcn, self::JSON_API_COLLECTION, true)) {
            return false;
        }

        $item = $type->typeArgs[0] ?? null;

        return $item instanceof ClassT && self::isResource($item->fqcn);
    }
}
