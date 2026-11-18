<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\RouteBindingOverride;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * The framework's own binding is `$query->where($field ?? $this->getRouteKeyName(), $value)`: the column
 * the route names, else the route key, matched against the value as it arrived. An override that runs
 * that lookup with further conditions ANDed beside it resolves fewer records, but only ever on that
 * column, so it is read as the framework's. Everything else could match on something else — another
 * column, another value, another model, a clause ORed past the match — and is left unread, which keeps
 * the segment the plain string a reader of the document can trust.
 */
it('reads an override as the framework\'s lookup only where it is one', function (string $source, ?string $field, bool $selfIsBound, bool $read): void {
    $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse("<?php\nfinal class Probe extends Model\n{\n{$source}\n}\n") ?? [];
    $method = (new NodeFinder)->findFirstInstanceOf((new NodeTraverser(new NameResolver))->traverse($statements), ClassMethod::class);

    expect($method)->toBeInstanceOf(ClassMethod::class);
    assert($method instanceof ClassMethod);

    expect(RouteBindingOverride::matchesLikeTheFramework($method, $field, $selfIsBound))->toBe($read);
})->with([
    // The narrowed lookup, by key and by a column the route names.
    'narrowed in a closure' => [
        'public function resolveRouteBinding($value, $field = null) { return self::query()->where(fn ($q) => $q->where(\'owner_id\', 1)->orWhereNull(\'owner_id\'))->where($field ?? $this->getRouteKeyName(), $value)->firstOrFail(); }',
        null, true, true,
    ],
    'narrowed in a closure, under a column the route names' => [
        'public function resolveRouteBinding($value, $field = null) { return self::query()->where(fn ($q) => $q->where(\'owner_id\', 1)->orWhereNull(\'owner_id\'))->where($field ?? $this->getRouteKeyName(), $value)->firstOrFail(); }',
        'slug', true, true,
    ],
    'narrowed with a plain where' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where(\'published\', true)->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, true,
    ],
    'narrowed by a local scope' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->ownedBy(1)->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, true,
    ],
    'ordered' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field ?? $this->getRouteKeyName(), $value)->orderBy(\'created_at\')->first(); }',
        null, false, true,
    ],
    'with ?: in place of ??' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field ?: $this->getRouteKeyName(), $value)->first(); }',
        'slug', false, true,
    ],
    'with the operator spelled out' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field ?? $this->getRouteKeyName(), \'=\', $value)->first(); }',
        null, false, true,
    ],
    'started by its own where' => [
        'public function resolveRouteBinding($value, $field = null) { return static::where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, true,
    ],
    'started on a new query of the model' => [
        'public function resolveRouteBinding($value, $field = null) { return $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, true,
    ],
    'started on the model itself' => [
        'public function resolveRouteBinding($value, $field = null) { return $this->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, true,
    ],
    'under other parameter names' => [
        'public function resolveRouteBinding($key, $column = null) { return static::query()->where($column ?? $this->getRouteKeyName(), $key)->first(); }',
        null, false, true,
    ],
    'the soft-deletable lookup' => [
        'public function resolveSoftDeletableRouteBinding($value, $field = null) { return static::query()->withTrashed()->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, true,
    ],
    'the lookup query, on the query it is handed' => [
        'public function resolveRouteBindingQuery($query, $value, $field = null) { return $query->where(\'owner_id\', 1)->where($field ?? $this->getRouteKeyName(), $value); }',
        null, false, true,
    ],
    // `$this->getRouteKeyName()` alone is the framework's column only where the route names none, and
    // `$field` alone only where it names one: `where(null, …)` is no lookup at all.
    'the route key alone, for a segment naming no column' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($this->getRouteKeyName(), $value)->first(); }',
        null, false, true,
    ],
    'the route key alone, under a column the route names' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($this->getRouteKeyName(), $value)->first(); }',
        'slug', false, false,
    ],
    'the route\'s column alone, for a segment naming one' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field, $value)->first(); }',
        'slug', false, true,
    ],
    'the route\'s column alone, for a segment naming none' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field, $value)->first(); }',
        null, false, false,
    ],
    // `self::` is the class that declares it, which a parent is, so its query is not the bound model's.
    'self:: in a parent of the bound model' => [
        'public function resolveRouteBinding($value, $field = null) { return self::query()->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, false,
    ],
    // The value is not what arrived.
    'a transformed value' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field ?? $this->getRouteKeyName(), strtoupper($value))->first(); }',
        null, false, false,
    ],
    'a cast value' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field ?? $this->getRouteKeyName(), (int) $value)->first(); }',
        null, false, false,
    ],
    'the value changed by a condition ahead of the match' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where(function ($q) use (&$value) { $value = strtoupper($value); })->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, false,
    ],
    'the value read again in a closure' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where(fn ($q) => $q->where(\'alias\', $value))->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, false,
    ],
    'the value spread' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field ?? $this->getRouteKeyName(), ...$value)->first(); }',
        null, false, false,
    ],
    'the value named' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field ?? $this->getRouteKeyName(), value: $value)->first(); }',
        null, false, false,
    ],
    'another operator' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field ?? $this->getRouteKeyName(), \'like\', $value)->first(); }',
        null, false, false,
    ],
    // The column is not the framework's.
    'a column of its own' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where(\'public_id\', $value)->first(); }',
        null, false, false,
    ],
    'a default column of its own' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field ?? \'public_id\', $value)->first(); }',
        null, false, false,
    ],
    'a column computed in SQL' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where(DB::raw(\'lower(slug)\'), $value)->first(); }',
        null, false, false,
    ],
    'a branch on the column the route names' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where($field === \'code\' ? \'legacy_code\' : ($field ?? $this->getRouteKeyName()), $value)->first(); }',
        'code', false, false,
    ],
    'the column the route names, read elsewhere too' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where(\'kind\', $field)->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, false,
    ],
    'a statement ahead of the lookup' => [
        'public function resolveRouteBinding($value, $field = null) { if ($field === \'code\') { $field = \'legacy_code\'; } return static::query()->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        'code', false, false,
    ],
    // The query is not the model's own.
    'another model\'s query' => [
        'public function resolveRouteBinding($value, $field = null) { return Owner::query()->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, false,
    ],
    'a relation' => [
        'public function resolveRouteBinding($value, $field = null) { return $this->entries()->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, false,
    ],
    'a helper\'s query' => [
        'public function resolveRouteBinding($value, $field = null) { return tenant()->playbooks()->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, false,
    ],
    'the query it is handed, replaced ahead of the lookup' => [
        'public function resolveRouteBindingQuery($query, $value, $field = null) { $query = Owner::query(); return $query->where($field ?? $this->getRouteKeyName(), $value); }',
        null, false, false,
    ],
    'the query parameter of a method that has none' => [
        'public function resolveRouteBinding($value, $field = null) { return $query->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, false,
    ],
    // The match is not a condition of the query itself.
    'the match ORed inside a group' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where(fn ($q) => $q->where(\'owner_id\', 1)->orWhere($field ?? $this->getRouteKeyName(), $value))->first(); }',
        null, false, false,
    ],
    'a callable taken in place of a call' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->where(...)->where($field ?? $this->getRouteKeyName(), $value); }',
        null, false, false,
    ],
    'a method name chosen at run time' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->{$this->scope}()->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, false,
    ],
    'no lookup at all' => [
        'public function resolveRouteBinding($value, $field = null) { return static::query()->first(); }',
        null, false, false,
    ],
    // Methods that hand out a key, or find a child through a relation, are never the model's own lookup.
    'a route key handed out' => [
        'public function getRouteKey() { return static::query()->where($field ?? $this->getRouteKeyName(), $value)->value(\'id\'); }',
        null, false, false,
    ],
    'a child found through its parent' => [
        'public function resolveChildRouteBinding($childType, $value, $field) { return static::query()->where($field ?? $this->getRouteKeyName(), $value)->first(); }',
        null, false, false,
    ],
]);

/**
 * A condition joined with OR, or written outside any group, sits beside the match rather than under it:
 * `key = ? or owner_id is null` resolves an ownerless record for any value at all.
 */
it('leaves unread a lookup a call could join past the match', function (string $call): void {
    $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse(
        "<?php\nfinal class Probe extends Model\n{\npublic function resolveRouteBinding(\$value, \$field = null) { return static::query()->where(\$field ?? \$this->getRouteKeyName(), \$value)->{$call}('owner_id')->first(); }\n}\n",
    ) ?? [];
    $method = (new NodeFinder)->findFirstInstanceOf($statements, ClassMethod::class);
    assert($method instanceof ClassMethod);

    expect(RouteBindingOverride::matchesLikeTheFramework($method, null, false))->toBeFalse();
})->with([...RouteBindingOverride::UNGROUPED, 'orWhere', 'orWhereNull', 'orWhereHas', 'ORWHERE']);

/**
 * `joinsWithOr()` decides by name, so the builder it reads is the authority: every method of either
 * builder starting `or` is the OR form of the method named by the rest — or one of the `order…` family,
 * which orders rather than filters.
 */
it('names every builder method that joins with OR', function (): void {
    $prefixed = [];
    foreach ([EloquentBuilder::class, QueryBuilder::class] as $builder) {
        foreach ((new ReflectionClass($builder))->getMethods() as $method) {
            if (str_starts_with(strtolower($method->getName()), 'or')) {
                $prefixed[$method->getName()] = $builder;
            }
        }
    }

    // A scan that stopped seeing its shapes would pass every row below on nothing.
    expect(count($prefixed))->toBeGreaterThan(40);

    foreach ($prefixed as $name => $builder) {
        $ordering = str_starts_with(strtolower($name), 'order');

        expect(RouteBindingOverride::joinsWithOr($name))->toBe(! $ordering, $name)
            ->and($ordering || method_exists($builder, lcfirst(substr($name, 2))))->toBeTrue($name.' is not the OR form of another method');
    }
});

/**
 * `static::where()` and `$this->where()` start a query only because the model has no `where()` of its
 * own: the call is forwarded to a new query on the model. One it declared would be some other method.
 */
it('starts a query on the model only through methods the model forwards or declares for it', function (): void {
    expect(method_exists(Model::class, 'where'))->toBeFalse()
        ->and(method_exists(Model::class, 'query'))->toBeTrue()
        ->and(method_exists(Model::class, 'newQuery'))->toBeTrue()
        ->and(method_exists(Model::class, 'newModelQuery'))->toBeTrue();
});

it('reads the three methods the framework looks a model\'s own segment up through, and no other', function (string $method, bool $reads): void {
    expect(RouteBindingOverride::reads($method))->toBe($reads);
})->with([
    ['resolveRouteBinding', true],
    ['resolveSoftDeletableRouteBinding', true],
    ['resolveRouteBindingQuery', true],
    ['getRouteKey', false],
    ['resolveChildRouteBinding', false],
    ['resolveSoftDeletableChildRouteBinding', false],
    ['resolveChildRouteBindingQuery', false],
]);
