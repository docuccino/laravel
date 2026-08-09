<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;

/**
 * The one place that names Laravel's resource classes (by FQCN string — the integration is always-on
 * but must not hard-reference symbols that only exist on newer Laravel). Distinguishes a plain
 * `JsonResource`, an anonymous resource collection, and a Laravel 13 first-party JSON:API resource,
 * plus a helper to tell whether a return type ultimately involves JSON:API (for the query-param
 * extension).
 */
final class ResourceReflector
{
    public const JSON_RESOURCE = 'Illuminate\\Http\\Resources\\Json\\JsonResource';

    public const ANONYMOUS_COLLECTION = 'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection';

    public const MISSING_VALUE = 'Illuminate\\Http\\Resources\\MissingValue';

    public const JSON_API_RESOURCE = 'Illuminate\\Http\\Resources\\JsonApi\\JsonApiResource';

    public const JSON_API_COLLECTION = 'Illuminate\\Http\\Resources\\JsonApi\\AnonymousResourceCollection';

    /** Whether an FQCN is any `JsonResource` (the schema mapper's trigger — includes subclasses). */
    public static function isResource(string $fqcn): bool
    {
        return is_a($fqcn, self::JSON_RESOURCE, true);
    }

    /** Whether an FQCN is an anonymous resource collection (`Resource::collection(...)`). */
    public static function isAnonymousCollection(string $fqcn): bool
    {
        return is_a($fqcn, self::ANONYMOUS_COLLECTION, true) || is_a($fqcn, self::JSON_API_COLLECTION, true);
    }

    /** Whether an FQCN is a Laravel first-party JSON:API resource (guarded by `class_exists`). */
    public static function isJsonApiResource(string $fqcn): bool
    {
        return class_exists(self::JSON_API_RESOURCE) && is_a($fqcn, self::JSON_API_RESOURCE, true);
    }

    /**
     * Whether a return type ultimately produces a JSON:API document — the resource itself or a
     * collection whose item is a JSON:API resource — so the `include`/`fields` params apply.
     */
    public static function involvesJsonApi(DType $type): bool
    {
        if (! $type instanceof ClassT) {
            return false;
        }

        if (self::isJsonApiResource($type->fqcn)) {
            return true;
        }

        if (! self::isAnonymousCollection($type->fqcn)) {
            return false;
        }

        $item = $type->typeArgs[0] ?? null;

        return $item instanceof ClassT && self::isJsonApiResource($item->fqcn);
    }
}
