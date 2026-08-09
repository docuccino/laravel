<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;

/**
 * {@see ResourceReflector} for the `timacdonald/json-api` family: its `JsonApiResource` (a subclass of
 * Laravel's `JsonResource`) and `JsonApiResourceCollection`, plus the check the parameters extension uses
 * to tell whether a return type ends up producing one. Classes are named by FQCN string, never by symbol —
 * the package is optional.
 */
final class TimacdonaldResourceReflector
{
    public const JSON_API_RESOURCE = 'TiMacDonald\\JsonApi\\JsonApiResource';

    public const JSON_API_COLLECTION = 'TiMacDonald\\JsonApi\\JsonApiResourceCollection';

    public static function isResource(string $fqcn): bool
    {
        return class_exists(self::JSON_API_RESOURCE) && is_a($fqcn, self::JSON_API_RESOURCE, true);
    }

    /**
     * The resource itself, or a collection whose item is one — either way the `include`/`fields` params
     * apply.
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
