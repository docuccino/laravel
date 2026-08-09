<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * One recovered Query-Builder allow-list entry. Its public `name` is the value a client sends —
 * `filter[status]`, `sort=name`, `include=author`; the `kind` is the bare string form or the factory
 * method (`exact`, `partial`, `scope`, `callback`, `custom`, `operator`, `trashed`, `belongsTo`,
 * `field`, `relationship`, `count`, `exists`, `aggregate`). The remaining members carry the richer
 * facts the trace recovers when present (design §Representation policies — facts stay stable in the
 * UIR regardless of how a policy later expresses them):
 *
 *   - `internal`: the second factory argument (`AllowedFilter::exact('status', 'status_code')`) — the
 *     underlying column the cast lookup keys on. The public `name` stays what is documented.
 *   - `hasDefault`/`default`: a constant-folded `->default(…)` modifier → the parameter's
 *     `schema.default`.
 *   - `nullable`: a `->nullable()` modifier → a description note (never an added enum case).
 *   - `comment`: the first sentence(s) of a line or block comment directly above the array entry —
 *     an integration-layer description overriding the generic kind description.
 *   - `typeColumn`: the subject-model column whose cast types this filter's value. Set for kinds
 *     that resolve a column: `exact`/`operator` (static) from the internal name, `callback`/`custom`
 *     from a recovered `$q->where(COLUMN, …)` body clause. When null the filter stays a plain string.
 *   - `filterClass`: the custom-filter FQCN (`AllowedFilter::custom('x', new F)` / `F::class`) — the
 *     extension reads its `#[QueryParameter]` attribute (an integration-layer override) and, absent
 *     that, analyses its `__invoke` body for a `where` column.
 *   - `columnSchema`/`enumTyped`: the base column schema the extension enriches the filter with from
 *     the resolved column's cast — or a scope-value parameter's type, or a custom-filter attribute —
 *     (an enum's backing values + `x-enumDescriptions`, or a native type). `enumTyped` drives the
 *     comma/whereIn array modelling and is set only for the whereIn kinds (`exact`); a single-value
 *     comparison (scope/callback/operator) keeps a scalar enum schema.
 *   - `example`: an example value (a custom-filter `#[QueryParameter]` attribute) for the parameter.
 */
final readonly class QbEntry
{
    /**
     * @param  array<string, mixed>|null  $columnSchema
     */
    public function __construct(
        public string $name,
        public string $kind,
        public ?string $internal = null,
        public bool $hasDefault = false,
        public string|int|float|bool|null $default = null,
        public bool $nullable = false,
        public ?string $comment = null,
        public ?array $columnSchema = null,
        public bool $enumTyped = false,
        public ?string $typeColumn = null,
        public ?string $filterClass = null,
        public mixed $example = null,
        // Set when the entry was produced by a PROJECT factory (a helper returning a Spatie
        // AllowedFilter, e.g. a ListFilters::enum(...) idiom) rather than a Spatie AllowedFilter::*
        // factory: `factoryEnum` is a backed-enum class-string argument (→ typed off it directly),
        // `factoryClass` the factory's declaring class (recorded as a fragment-cache dependency).
        public ?string $factoryEnum = null,
        public ?string $factoryClass = null,
    ) {}

    /** The underlying column a cast lookup keys on: the recovered internal name, else the public name. */
    public function column(): string
    {
        return $this->internal ?? $this->name;
    }

    /**
     * A copy carrying the recovered column schema (an enum's values / a native type) — the extension's
     * enrichment step, keeping the pure trace-recovered entry immutable. `comment`, `default` and
     * `example` may be overridden too, so a custom-filter `#[QueryParameter]` attribute can set the
     * description/default/example alongside the schema.
     *
     * @param  array<string, mixed>|null  $columnSchema
     */
    public function withColumn(
        ?array $columnSchema,
        bool $enumTyped,
        ?string $comment = null,
        bool $hasDefault = false,
        string|int|float|bool|null $default = null,
        mixed $example = null,
    ): self {
        return new self(
            $this->name,
            $this->kind,
            $this->internal,
            $hasDefault ? $hasDefault : $this->hasDefault,
            $hasDefault ? $default : $this->default,
            $this->nullable,
            $comment ?? $this->comment,
            $columnSchema,
            $enumTyped,
            $this->typeColumn,
            $this->filterClass,
            $example ?? $this->example,
            $this->factoryEnum,
            $this->factoryClass,
        );
    }
}
