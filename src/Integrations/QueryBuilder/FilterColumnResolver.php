<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Laravel\Integrations\Eloquent\CastSchema;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;

/**
 * Resolves the typed schema a subject model's cast pins for an exact-filter column, reusing the
 * Eloquent integration's cast recovery ({@see EloquentModelReflector} reads `$casts` by reflection —
 * never booting the model — and {@see CastSchema} maps a native cast to a schema fragment) and the
 * shared {@see EnumReflection} machinery. Pure reflection: no PHPStan, no engine, so it runs equally
 * in-process (the parameters extension) and out-of-process (the real-engine fixture proof).
 *
 * Only the subject model's own columns are typed; a relation-path column (`posts.title`) or an
 * unresolvable model degrades to {@see FilterColumn::none()} (the filter stays a plain string).
 */
final class FilterColumnResolver
{
    public function __construct(
        private readonly EloquentModelReflector $reflector = new EloquentModelReflector,
    ) {}

    /**
     * The cast-pinned column shape for `$column` on `$model`, or {@see FilterColumn::none()} when the
     * model is unresolvable, the column is a dotted relation path, or the column has no recognised cast.
     */
    public function resolve(string $model, string $column): FilterColumn
    {
        if (str_contains($column, '.') || ! EloquentModelReflector::isModel($model)) {
            return FilterColumn::none();
        }

        $cast = $this->reflector->facts($model)['casts'][$column] ?? null;
        if ($cast === null) {
            return FilterColumn::none();
        }

        if (CastSchema::isEnum($cast)) {
            $enum = explode(':', $cast, 2)[0];
            $file = EnumReflection::file($enum);

            return FilterColumn::enum($enum, $file !== null ? [$file] : []);
        }

        $scalar = CastSchema::forCast($cast);

        // A custom caster class CastSchema does not recognise → leave the filter a plain string.
        return $scalar === null ? FilterColumn::none() : FilterColumn::scalar($scalar);
    }
}
