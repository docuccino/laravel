<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\QueryBuilder\FilterColumn;
use Docuccino\Laravel\Integrations\QueryBuilder\FilterColumnResolver;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Codex;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\ContestedRelationModel;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\CustomCaster;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\FilterCastModel;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\FilterRelationModel;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Locker;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Passcard;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Turnstile;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\VetoedRelationModel;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Waybill;
use Workbench\App\Enums\WidgetStatus;

/**
 * Dataset coverage over the cast → filter-column mapping (the resolver reuses the Eloquent
 * integration's cast recovery via native reflection): every native cast kind, an enum cast, a custom
 * caster, a no-cast column and a dotted relation path — each proven against a real model's `$casts`.
 */
it('maps a subject-model column cast to its filter-column shape', function (string $column, string $kind, ?array $scalarSchema): void {
    $resolved = (new FilterColumnResolver)->resolve(FilterCastModel::class, $column);

    expect($resolved->kind)->toBe($kind);

    if ($kind === FilterColumn::KIND_SCALAR) {
        expect($resolved->scalarSchema)->toBe($scalarSchema);
    }

    if ($kind === FilterColumn::KIND_ENUM) {
        expect($resolved->enum)->toBe(WidgetStatus::class)
            ->and($resolved->dependencyFiles)->not->toBe([]);
    }
})->with([
    'enum cast' => ['status', FilterColumn::KIND_ENUM, null],
    'boolean cast' => ['active', FilterColumn::KIND_SCALAR, ['type' => 'boolean']],
    'integer cast' => ['quantity', FilterColumn::KIND_SCALAR, ['type' => 'integer']],
    'float cast' => ['rating', FilterColumn::KIND_SCALAR, ['type' => 'number']],
    'datetime cast' => ['published_at', FilterColumn::KIND_SCALAR, ['type' => 'string', 'format' => 'date-time']],
    'immutable_date cast' => ['archived_on', FilterColumn::KIND_SCALAR, ['type' => 'string', 'format' => 'date']],
    'decimal cast' => ['price', FilterColumn::KIND_SCALAR, ['type' => 'string']],
    'string cast' => ['nickname', FilterColumn::KIND_SCALAR, ['type' => 'string']],
    'custom caster' => ['custom', FilterColumn::KIND_NONE, null],
    'no cast' => ['untyped_column', FilterColumn::KIND_NONE, null],
    'dotted relation path' => ['author.name', FilterColumn::KIND_NONE, null],
]);

it('degrades to none for a non-model subject', function (): void {
    expect((new FilterColumnResolver)->resolve('Not\\A\\Model', 'status')->kind)
        ->toBe(FilterColumn::KIND_NONE);
});

/**
 * A filter on the model's primary key types off the key schema, mirroring the path-parameter
 * precedence: a HasUuids/HasUlids format outranks a cast, `$keyType` decides the rest, and an
 * unrecognised custom caster on the key still falls back to the declared key type.
 */
it('types a primary-key filter from the model\'s key schema', function (string $model, array $scalarSchema): void {
    $resolved = (new FilterColumnResolver)->resolve($model, 'id');

    expect($resolved->kind)->toBe(FilterColumn::KIND_SCALAR)
        ->and($resolved->scalarSchema)->toBe($scalarSchema);
})->with([
    'HasUuids key' => [Vault::class, ['type' => 'string', 'format' => 'uuid']],
    'HasUlids key' => [Waybill::class, ['type' => 'string', 'format' => 'ulid']],
    'default int key' => [FilterCastModel::class, ['type' => 'integer']],
    'string keyType' => [Passcard::class, ['type' => 'string']],
    'uuid format beats a stale string cast' => [Locker::class, ['type' => 'string', 'format' => 'uuid']],
    'custom caster on the key falls back to the key type' => [Turnstile::class, ['type' => 'integer']],
]);

/**
 * The foreign-key hop: a column that is exactly one `belongsTo` relation's foreign key types off the
 * RELATED model's referenced key (its ownerKey, else its primary key), covering default and explicit
 * foreign keys, named arguments, a relation-name argument, and a renamed related key. Happy rows carry
 * the related model's file as a dependency.
 */
it('types a belongsTo foreign-key filter from the related model\'s referenced key', function (string $column, array $scalarSchema, string $related): void {
    $resolved = (new FilterColumnResolver)->resolve(FilterRelationModel::class, $column);

    expect($resolved->kind)->toBe(FilterColumn::KIND_SCALAR)
        ->and($resolved->scalarSchema)->toBe($scalarSchema)
        ->and($resolved->dependencyFiles)->toContain((new ReflectionClass($related))->getFileName());
})->with([
    'default fk to a uuid key' => ['vault_id', ['type' => 'string', 'format' => 'uuid'], Vault::class],
    'camelCase method snake-cases the fk' => ['vault_keeper_id', ['type' => 'string', 'format' => 'uuid'], Vault::class],
    'chained ->withDefault() still reads' => ['waybill_id', ['type' => 'string', 'format' => 'ulid'], Waybill::class],
    'explicit fk argument' => ['custom_owner_id', ['type' => 'string', 'format' => 'uuid'], Vault::class],
    'named foreignKey: argument' => ['named_keeper_id', ['type' => 'string', 'format' => 'ulid'], Waybill::class],
    'relation-name argument names the fk' => ['archive_id', ['type' => 'string', 'format' => 'uuid'], Vault::class],
    'default fk to an int key' => ['sibling_id', ['type' => 'integer'], FilterCastModel::class],
    'ownerKey naming a cast column' => ['reference_key', ['type' => 'integer'], FilterCastModel::class],
    'renamed related primary key' => ['codex_guid', ['type' => 'string', 'format' => 'uuid'], Codex::class],
    'string-literal related class name' => ['ancient_id', ['type' => 'string', 'format' => 'uuid'], Vault::class],
]);

it('types a foreign key off an enum-cast ownerKey as the enum itself', function (): void {
    $resolved = (new FilterColumnResolver)->resolve(FilterRelationModel::class, 'flagged_key');

    expect($resolved->kind)->toBe(FilterColumn::KIND_ENUM)
        ->and($resolved->enum)->toBe(WidgetStatus::class)
        ->and($resolved->dependencyFiles)->toContain((new ReflectionClass(FilterCastModel::class))->getFileName());
});

/**
 * A refused relation vetoes what it might own: its literal foreign key contests exactly that column
 * (the clean match beside it still types), and a wildcard — no readable key at all — suppresses every
 * hop answer on the model. Vetoed outcomes still carry the files they read.
 */
it('vetoes a matched column a partially-readable relation contests', function (string $model, string $column, string $kind): void {
    $resolved = (new FilterColumnResolver)->resolve($model, $column);

    expect($resolved->kind)->toBe($kind)
        ->and($resolved->dependencyFiles)->not->toBe([]);
})->with([
    'literal-key refusal contests its column' => [ContestedRelationModel::class, 'contested_id', FilterColumn::KIND_NONE],
    'literal-key refusal with no readable match' => [ContestedRelationModel::class, 'branch_id', FilterColumn::KIND_NONE],
    'non-model target contests its column' => [ContestedRelationModel::class, 'relic_id', FilterColumn::KIND_NONE],
    'clean match beside literal-key refusals still types' => [ContestedRelationModel::class, 'vault_id', FilterColumn::KIND_SCALAR],
    'wildcard suppresses an otherwise-clean match' => [VetoedRelationModel::class, 'vault_id', FilterColumn::KIND_NONE],
]);

it('keys a non-model refusal on the file it read to refuse', function (): void {
    $resolved = (new FilterColumnResolver)->resolve(ContestedRelationModel::class, 'relic_id');

    expect($resolved->dependencyFiles)->toContain((new ReflectionClass(CustomCaster::class))->getFileName());
});

/**
 * The hop's refusals: an unknowable referenced column, a wrong guess at a renamed key, a morphTo
 * column, two relations contesting one column, and a column no relation owns. A refusal still
 * carries the files it read — edited, any of them could become an answer.
 */
it('refuses a foreign-key column the relations cannot truthfully type', function (string $column): void {
    $resolved = (new FilterColumnResolver)->resolve(FilterRelationModel::class, $column);

    expect($resolved->kind)->toBe(FilterColumn::KIND_NONE)
        ->and($resolved->dependencyFiles)->not->toBe([]);
})->with([
    'ownerKey naming an uncast non-key column' => ['opaque_key'],
    'ownerKey naming a custom-cast column' => ['sealed_key'],
    'default fk guessed against a renamed key' => ['codex_id'],
    'morphTo id column' => ['attachable_id'],
    'two relations contesting one fk' => ['shared_id'],
    'a column no relation owns' => ['unrelated_column'],
]);
