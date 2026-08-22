<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Laravel\Integrations\Eloquent\BelongsToReader;
use Docuccino\Laravel\Integrations\Eloquent\CastSchema;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;

/**
 * Resolves the typed schema a subject model pins for an exact-filter column — its cast, or the
 * primary-key schema when the column IS the key — reusing the Eloquent integration's recovery
 * ({@see EloquentModelReflector} reads `$casts` and the key facts by reflection — never booting the
 * model — and {@see CastSchema} maps a native cast to a schema fragment) and the shared
 * {@see EnumReflection} machinery. Precedence mirrors {@see EloquentModelReflector::columnSchemaFor()}
 * so a filter and a path parameter can't document the same column differently. Pure reflection: no
 * PHPStan, no engine, so it runs equally in-process (the parameters extension) and out-of-process
 * (the real-engine fixture proof).
 *
 * A column nothing on the model types may still be a `belongsTo` foreign key, in which case the
 * RELATED model's referenced key types it ({@see BelongsToReader}). A relation-path column
 * (`posts.title`) or an unresolvable model degrades to {@see FilterColumn::none()} (the filter stays a
 * plain string).
 */
final class FilterColumnResolver
{
    public function __construct(
        private readonly EloquentModelReflector $reflector = new EloquentModelReflector,
        private readonly BelongsToReader $belongsTo = new BelongsToReader,
    ) {}

    /**
     * The typed column shape for `$column` on `$model`, or {@see FilterColumn::none()} when the model
     * is unresolvable, the column is a dotted relation path, or nothing on the model types it.
     */
    public function resolve(string $model, string $column): FilterColumn
    {
        if (str_contains($column, '.') || ! EloquentModelReflector::isModel($model)) {
            return FilterColumn::none();
        }

        return $this->ownColumn($model, $column) ?? $this->foreignKeyColumn($model, $column);
    }

    /**
     * The shape the model's own declarations pin for `$column`: a uuid/ulid key format first, then the
     * column's cast, then the plain key schema. Null only when the column has no cast and is not the
     * key — the one case the foreign-key hop could still answer.
     */
    private function ownColumn(string $model, string $column): ?FilterColumn
    {
        $facts = $this->reflector->facts($model);
        $isKey = $column === $facts['keyName'];

        // HasUuids/HasUlids fix the key's format outright, beating a stale cast — mirrors
        // EloquentModelReflector::columnSchemaFor().
        if ($isKey && isset($facts['keySchema']['format'])) {
            return FilterColumn::scalar($facts['keySchema']);
        }

        $cast = $facts['casts'][$column] ?? null;
        if ($cast !== null) {
            if (CastSchema::isEnum($cast)) {
                $enum = explode(':', $cast, 2)[0];
                $file = EnumReflection::file($enum);

                return FilterColumn::enum($enum, $file !== null ? [$file] : []);
            }

            $scalar = CastSchema::forCast($cast);
            if ($scalar !== null) {
                return FilterColumn::scalar($scalar);
            }

            // A custom caster CastSchema does not recognise: the key still has its declared key type;
            // any other column's wire form belongs to the caster, so it stays a plain string.
            return $isKey ? FilterColumn::scalar($facts['keySchema']) : FilterColumn::none();
        }

        return $isKey ? FilterColumn::scalar($facts['keySchema']) : null;
    }

    /**
     * The shape a `belongsTo` relation's referenced key pins for a foreign-key column. The column must
     * be exactly ONE readable relation's foreign key — zero or several matches refuse, so the answer is
     * a function of the declarations and never of method order. The referenced column (the ownerKey,
     * else the related key) resolves through {@see ownColumn()} on the related model — deliberately no
     * second hop, and a refusal there (an uncast non-key ownerKey, a custom caster) propagates.
     */
    private function foreignKeyColumn(string $model, string $column): FilterColumn
    {
        ['readable' => $readable, 'refused' => $refused] = $this->belongsTo->relations($model);
        if ($readable === [] && $refused === []) {
            return FilterColumn::none();
        }

        // EVERY related declaration joins the dependency set — match or not, refused or not: a change
        // to any related $primaryKey changes which default foreign keys exist, so it can create or
        // remove a match for this very column, and a warm fragment must see that.
        $files = [];
        foreach ([...$readable, ...$refused] as $relation) {
            $files = [...$files, ...DeclarationFiles::of($relation['related'])];
        }
        $files = array_values(array_unique($files));

        $matches = array_values(array_filter($readable, static fn (array $relation): bool => $relation['foreignKey'] === $column));

        // A refused relation was read only in part: its literal foreign key contests the column, and
        // one with no readable key could serve ANY column — either way no answer here is safely
        // exclusive, and a vague-but-true string beats a precise-but-false format.
        $vetoed = array_filter($refused, static fn (array $refusal): bool => $refusal['foreignKey'] === $column || $refusal['foreignKey'] === null) !== [];

        if ($vetoed || count($matches) !== 1) {
            return FilterColumn::none()->withDependencyFiles($files);
        }

        $referenced = $matches[0]['ownerKey'] ?? $this->reflector->facts($matches[0]['related'])['keyName'];

        return ($this->ownColumn($matches[0]['related'], $referenced) ?? FilterColumn::none())
            ->withDependencyFiles($files);
    }
}
