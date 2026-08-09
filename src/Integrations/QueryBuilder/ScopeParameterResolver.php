<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use BackedEnum;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Laravel\Integrations\Eloquent\CastSchema;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Types a `AllowedFilter::scope('popular')` filter from the subject model's local scope method
 * (`scopePopular`): the value the client sends is the scope's VALUE parameter — the second, after the
 * `Builder $query` — so its declared type pins the schema. A backed enum yields the enum's backing
 * values (routed through the shared {@see EnumReflection} machinery, so `#[CaseDescription]` prose
 * lands as `x-enumDescriptions`); a native scalar (`int`/`string`/`bool`/`float`) yields that scalar's
 * schema via the Eloquent cast table; a scope with no value parameter, an untyped one, or a
 * class/union type degrades to {@see FilterColumn::none()} (the filter stays a plain string).
 *
 * Pure reflection: no PHPStan, no engine, so it types scope filters equally in-process and inside the
 * out-of-process fixture proof.
 */
final class ScopeParameterResolver
{
    /** The cast-typed shape a model scope's value parameter pins for `$filterName`, else none. */
    public function resolve(string $model, string $filterName): FilterColumn
    {
        $method = 'scope'.Str::studly($filterName);
        if (! class_exists($model) || ! method_exists($model, $method)) {
            return FilterColumn::none();
        }

        try {
            $parameters = (new ReflectionMethod($model, $method))->getParameters();
        } catch (Throwable) {
            return FilterColumn::none();
        }

        // Parameter 0 is the Builder; parameter 1 is the value the filter passes through.
        $value = $parameters[1] ?? null;
        $type = $value?->getType();
        if (! $type instanceof ReflectionNamedType) {
            return FilterColumn::none();
        }

        return $type->isBuiltin()
            ? self::scalar($type->getName())
            : self::enum($type->getName());
    }

    private static function enum(string $type): FilterColumn
    {
        // Only a BACKED enum has documentable values; a pure enum degrades to a plain string.
        if (! is_subclass_of($type, BackedEnum::class)) {
            return FilterColumn::none();
        }

        $file = EnumReflection::file($type);

        return FilterColumn::enum($type, $file !== null ? [$file] : []);
    }

    private static function scalar(string $type): FilterColumn
    {
        $schema = CastSchema::forCast($type);

        return $schema === null ? FilterColumn::none() : FilterColumn::scalar($schema);
    }
}
