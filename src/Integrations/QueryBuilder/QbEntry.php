<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * One recovered Query-Builder allow-list entry. `name` is what a client sends (`filter[status]`,
 * `sort=name`); `kind` is the bare string form or the factory method (`exact`, `partial`, `scope`,
 * `callback`, `custom`, `operator`, `trashed`, `belongsTo`, `field`, `relationship`, `count`, `exists`,
 * `aggregate`). The rest are facts the trace recovers when present, and stay stable in the UIR however
 * a representation policy later expresses them. Worth calling out:
 *
 *   - `internal`: the factory's underlying-column argument (`exact('status', 'status_code')`), which the
 *     cast lookup keys on. `name` stays what's documented.
 *   - `nullable`: from a `->nullable()` modifier — becomes a description note, never an extra enum case.
 *   - `comment`: the leading comment above the array entry, overriding the generic kind description.
 *   - `typeColumn`: the subject-model column whose cast types the value; null leaves it a plain string.
 *   - `filterClass`: a custom filter's FQCN — written at the call site, or folded out of a project
 *     factory's body — whose `#[QueryParameter]` or `__invoke` body the extension reads.
 *   - `enumTyped`: drives comma/whereIn array modelling. Only the whereIn kinds set it — a single-value
 *     comparison (scope/callback/operator) keeps a scalar enum schema.
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
        // Set when a PROJECT factory produced the entry (a `ListFilters::enum(...)` style helper) rather
        // than a Spatie `AllowedFilter::*` one: the backed-enum class-string argument to type off, and
        // the factory's declaring class, which is a fragment-cache dependency.
        public ?string $factoryEnum = null,
        public ?string $factoryClass = null,
    ) {}

    /** The column a cast lookup keys on: the internal name if recovered, else the public one. */
    public function column(): string
    {
        return $this->internal ?? $this->name;
    }

    /**
     * A copy carrying the enriched column schema, keeping the trace-recovered entry immutable. Comment,
     * default and example can be overridden too, so a custom filter's `#[QueryParameter]` can set them
     * alongside the schema.
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

    /** A copy carrying the custom filter class a factory body's return fold recovered. */
    public function withFilterClass(string $filterClass): self
    {
        return new self(
            $this->name,
            $this->kind,
            $this->internal,
            $this->hasDefault,
            $this->default,
            $this->nullable,
            $this->comment,
            $this->columnSchema,
            $this->enumTyped,
            $this->typeColumn,
            $filterClass,
            $this->example,
            $this->factoryEnum,
            $this->factoryClass,
        );
    }
}
