<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\Support\JsonApiTopLevel;
use Illuminate\Http\Resources\Json\JsonResource;

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

    /** The container key `JsonApiResource::resolveServerImplementationUsing()` binds its callback under. */
    public const SERVER_IMPLEMENTATION_RESOLVER = self::JSON_API_RESOURCE.':$serverImplementationResolver';

    public static function isResource(string $fqcn): bool
    {
        return class_exists(self::JSON_API_RESOURCE) && is_a($fqcn, self::JSON_API_RESOURCE, true);
    }

    /**
     * The class whose `resolveResourceData()` sends `$fqcn` where that is not the package's resource
     * object, or null where it is. From Laravel 12.45 `resolve()` sends what `resolveResourceData()`
     * returns — the base's is `toAttributes()` — and the package declares its own, returning `toArray()`,
     * only from v1.0.0-beta.10. Read from the installed code, which `composer.lock` keys.
     */
    public static function sentOtherwise(string $fqcn): ?string
    {
        $declaring = JsonApiTopLevel::declaring($fqcn, 'resolveResourceData');

        return $declaring === null || $declaring === self::JSON_API_RESOURCE ? null : $declaring;
    }

    /** Why `$fqcn` is not sent as its resource object: Laravel's base answers for it, or `$sender`'s override. */
    public static function notSent(string $fqcn, string $sender): Diagnostic
    {
        if ($sender === JsonResource::class) {
            return new Diagnostic(
                severity: Severity::Warning,
                code: 'timacdonald-json-api.resource-object-not-sent',
                message: sprintf('%s is sent as its attributes alone, not as a JSON:API resource object: Laravel 12.45 and later send a resource as its resolveResourceData() returns, which the installed timacdonald/json-api does not declare. Its resource objects are published as open objects.', $fqcn),
                help: 'Upgrade timacdonald/json-api to v1.0.0-beta.10 or later, which sends the resource object again.',
            );
        }

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'timacdonald-json-api.resource-object-not-sent',
            message: sprintf('%s is sent as the resolveResourceData() declared on %s returns, which is not analysed. Its resource objects are published as open objects.', $fqcn, $sender),
            help: 'Remove the override so the package sends the resource object, or describe the response explicitly.',
        );
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
