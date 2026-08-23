<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Laravel\Integrations\Support\PaginatorPageParameter;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * Turns recovered {@see QueryBuilderFacts} into query-parameter specs. The facts themselves are
 * policy-independent; this is the only place their EXPRESSION is decided — bracketed `filter[status]`
 * params vs one `filter` deep-object, comma-serialised enum lists for sort/include, sparse fieldsets, pagination,
 * and how a column cast surfaces (an enum becomes a comma-serialised array so Spatie's whereIn split
 * stays valid; a native cast is just its scalar type). Names and the effective delimiter come from
 * {@see QueryBuilderConfig}; the filter/fields shapes from the {@see RepresentationPolicy}. Pure and
 * deterministic, so every branch is dataset-testable.
 */
final class QueryBuilderParameters
{
    private const WHERE_IN_NOTE = 'Accepts a comma-separated list of values (matched as `whereIn`).';

    private const NULLABLE_NOTE = 'Accepts `null` to filter for absent values.';

    /** Spatie's `FiltersTrashed` accepts exactly these. */
    private const TRASHED_VALUES = ['with', 'only'];

    /** Filter kinds whose value type is user code's to decide — never guessed. See {@see schemaWithoutColumn()}. */
    private const OPAQUE_KINDS = ['callback', 'custom'];

    /**
     * Filter kind → human description fragment.
     *
     * @var array<string, string>
     */
    private const FILTER_DESCRIPTIONS = [
        'default' => 'Partial-match filter',
        'partial' => 'Partial-match filter',
        'exact' => 'Exact-match filter',
        'beginsWithStrict' => 'Begins-with filter',
        'endsWithStrict' => 'Ends-with filter',
        'scope' => 'Query-scope filter',
        'callback' => 'Custom filter',
        'custom' => 'Custom filter',
        'operator' => 'Operator filter',
        'trashed' => 'Soft-delete filter: `with` includes soft-deleted records, `only` returns only soft-deleted; omit to exclude them.',
        'belongsTo' => 'Relationship filter',
    ];

    /**
     * @return list<QueryParameterSpec>
     */
    public function build(QueryBuilderFacts $facts, RepresentationPolicy $policy, QueryBuilderConfig $config = new QueryBuilderConfig): array
    {
        return [
            ...$this->filterParameters($facts, $policy, $config),
            ...$this->sortParameters($facts, $config),
            ...$this->includeParameters($facts, $config),
            ...$this->fieldParameters($facts, $policy, $config),
            ...$this->paginationParameters($facts),
        ];
    }

    /**
     * @return list<QueryParameterSpec>
     */
    private function filterParameters(QueryBuilderFacts $facts, RepresentationPolicy $policy, QueryBuilderConfig $config): array
    {
        if ($facts->filters === []) {
            return [];
        }

        if ($policy->filtersDeepObject()) {
            $properties = [];
            foreach ($facts->filters as $filter) {
                $properties[$filter->name] = $this->filterProperty($filter, $config);
            }

            return [new QueryParameterSpec(
                name: $config->filter,
                schema: ['type' => 'object', 'properties' => $properties],
                description: 'Filter the result set.',
                style: 'deepObject',
                explode: true,
            )];
        }

        $specs = [];
        foreach ($facts->filters as $filter) {
            [$schema, $style, $explode] = $this->filterSchema($filter, $config);
            $specs[] = new QueryParameterSpec(
                name: $config->filterKey($filter->name),
                schema: $schema,
                description: $this->filterDescription($filter, $config),
                style: $style,
                explode: $explode,
                example: $filter->example,
            );
        }

        return $specs;
    }

    /**
     * `[schema, style, explode]` for a bracketed filter: the soft-delete filter is a fixed enum, an
     * enum-typed one the effective whereIn shape ({@see whereInSchema()}, carrying comma style only on
     * the comma form), a resolved column its scalar schema, everything else {@see schemaWithoutColumn()}.
     *
     * @return array{0: array<string, mixed>, 1: string|null, 2: bool|null}
     */
    private function filterSchema(QbEntry $filter, QueryBuilderConfig $config): array
    {
        if ($filter->kind === 'trashed') {
            return [$this->withDefault(self::trashedSchema(), $filter), null, null];
        }

        if ($filter->enumTyped && $filter->columnSchema !== null) {
            $schema = $this->withDefault(self::whereInSchema($filter->columnSchema, $config), $filter);

            return $config->splitsOnComma() ? [$schema, 'form', false] : [$schema, null, null];
        }

        $schema = $filter->columnSchema ?? self::schemaWithoutColumn($filter);

        return [$this->withDefault($schema, $filter), null, null];
    }

    /**
     * The enum whereIn shape under the effective delimiter: the comma-serialised array on the default,
     * the item schema itself when nothing splits (one value per request is then the whole contract),
     * and the vague-true plain string under any other separator — the comma-array form would document
     * a wire form Spatie rejects. {@see commaListSpec()} is the sort/include analogue.
     *
     * @param  array<string, mixed>  $columnSchema
     * @return array<string, mixed>
     */
    private static function whereInSchema(array $columnSchema, QueryBuilderConfig $config): array
    {
        if ($config->splitsOnComma()) {
            return self::commaList($columnSchema);
        }

        return $config->delimiter === '' ? $columnSchema : ['type' => 'string'];
    }

    /**
     * The schema for a filter no column typed. A `LIKE`-matching kind is a string by construction; a
     * `callback`/`custom` one takes whatever its user code takes, so it says NOTHING rather than pinning a
     * `type` a better-informed producer downstream could only contradict.
     *
     * @return array<string, mixed>
     */
    private static function schemaWithoutColumn(QbEntry $filter): array
    {
        return in_array($filter->kind, self::OPAQUE_KINDS, true) ? [] : ['type' => 'string'];
    }

    /**
     * A filter as a deepObject property — description inline, since a property can't carry style/explode.
     *
     * @return array<string, mixed>
     */
    private function filterProperty(QbEntry $filter, QueryBuilderConfig $config): array
    {
        if ($filter->kind === 'trashed') {
            $schema = self::trashedSchema();
        } elseif ($filter->enumTyped && $filter->columnSchema !== null) {
            $schema = self::whereInSchema($filter->columnSchema, $config);
        } else {
            $schema = $filter->columnSchema ?? self::schemaWithoutColumn($filter);
        }

        $schema['description'] = $this->filterDescription($filter, $config);
        if ($filter->example !== null) {
            $schema['example'] = $filter->example;
        }

        return $this->withDefault($schema, $filter);
    }

    /**
     * A string enum, never a `whereIn` array — only one mode can be selected.
     *
     * @return array<string, mixed>
     */
    private static function trashedSchema(): array
    {
        return ['type' => 'string', 'enum' => self::TRASHED_VALUES];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function withDefault(array $schema, QbEntry $filter): array
    {
        if ($filter->hasDefault) {
            // Even under the enum-array modelling the default is the single value, not a wrapped list.
            $schema['default'] = $filter->default;
        }

        return $schema;
    }

    /** A filter's description: its comment (else the kind fragment), plus whereIn/nullable notes. */
    private function filterDescription(QbEntry $filter, QueryBuilderConfig $config): string
    {
        $base = $filter->comment ?? self::filterKindDescription($filter->kind);

        $notes = [];
        if ($filter->enumTyped) {
            $notes = [...$notes, ...self::whereInNotes($filter, $config)];
        }
        if ($filter->nullable) {
            $notes[] = self::NULLABLE_NOTE;
        }

        if ($notes === []) {
            return $base;
        }

        // Terminate the lead so the appended notes read as sentences. Note-less filters keep their bare
        // fragment, which is what the goldens pin.
        $lead = preg_match('/[.!?]$/', $base) === 1 ? $base : $base.'.';

        return implode(' ', [$lead, ...$notes]);
    }

    /**
     * The whereIn notes for an enum-typed filter under the effective delimiter. No splitting means no
     * list to describe; a custom separator names itself and, since the schema degraded to a plain
     * string, restates the inline values so the information isn't lost (a hoisted `$ref` has no values
     * to restate at this layer).
     *
     * @return list<string>
     */
    private static function whereInNotes(QbEntry $filter, QueryBuilderConfig $config): array
    {
        if ($config->splitsOnComma()) {
            return [self::WHERE_IN_NOTE];
        }

        if ($config->delimiter === '') {
            return [];
        }

        $notes = [sprintf('Accepts a `%s`-separated list of values (matched as `whereIn`).', $config->delimiter)];

        $values = $filter->columnSchema['enum'] ?? null;
        $scalars = is_array($values) ? array_values(array_filter($values, is_scalar(...))) : [];
        if ($scalars !== []) {
            $notes[] = sprintf('Values: %s.', implode(', ', array_map(static fn (bool|float|int|string $value): string => (string) $value, $scalars)));
        }

        return $notes;
    }

    /**
     * The allow-list is a closed set the trace fully recovered, so the value domain is stated as an
     * enum regardless of strict mode — exactly as `filter[trashed]` and enum-cast filters already are.
     * Strict mode governs only the documented 400, which travels separately.
     *
     * @return list<QueryParameterSpec>
     */
    private function sortParameters(QueryBuilderFacts $facts, QueryBuilderConfig $config): array
    {
        if ($facts->sorts === []) {
            return [];
        }

        // Spatie's `-name` convention: every allowed sort has an ascending and a descending form.
        // AllowedSort ltrim()s a leading `-` off the allow-listed name, so the base is the stripped one.
        $values = [];
        $bases = [];
        foreach ($facts->sorts as $sort) {
            $name = ltrim($sort->name, '-');
            if (! in_array($name, $bases, true)) {
                $bases[] = $name;
                $values[] = $name;
                $values[] = '-'.$name;
            }
        }

        $description = sprintf('Sort by: %s (prefix `-` for descending).', implode(', ', $bases));

        return [self::commaListSpec($config->sort, $values, $description, $config, $facts->defaultSorts)];
    }

    /**
     * @return list<QueryParameterSpec>
     */
    private function includeParameters(QueryBuilderFacts $facts, QueryBuilderConfig $config): array
    {
        if ($facts->includes === []) {
            return [];
        }

        $names = array_map(static fn (QbEntry $i): string => $i->name, $facts->includes);
        $description = sprintf('Include related resources: %s.', implode(', ', $names));

        return [self::commaListSpec($config->include, self::includeValues($facts->includes, $config), $description, $config)];
    }

    /**
     * The one comma-serialised list shape (`form`, `explode: false`, items enumming `$values`) sort and
     * include share — degraded like {@see whereInSchema()} when the app splits on a custom delimiter,
     * or to the single-value item schema when nothing splits.
     *
     * A default (Spatie's `defaultSort`) lands on the schema only where every value is a member of the
     * emitted enum — a defaultSort needs no allow-listing, and a default violating its own schema would
     * be a lie — and only on the comma form, whose array type it matches. Anywhere else it is stated in
     * the description instead.
     *
     * @param  list<string>  $values
     * @param  list<string>  $defaults
     */
    private static function commaListSpec(string $name, array $values, string $description, QueryBuilderConfig $config, array $defaults = []): QueryParameterSpec
    {
        $items = ['type' => 'string', 'enum' => $values];
        $onSchema = $defaults !== [] && array_diff($defaults, $values) === [];

        if ($config->splitsOnComma()) {
            $schema = self::commaList($items);
            if ($onSchema) {
                // The defaultSort chain as written, an array because several defaults compose.
                $schema['default'] = $defaults;
            }

            return new QueryParameterSpec($name, $schema, self::withDefaultsNote($description, $onSchema ? [] : $defaults), style: 'form', explode: false);
        }

        $description = self::withDefaultsNote(
            $config->delimiter === '' ? $description : $description.sprintf(' Values are separated by `%s`.', $config->delimiter),
            $defaults,
        );

        return new QueryParameterSpec($name, $config->delimiter === '' ? $items : ['type' => 'string'], $description);
    }

    /**
     * States `$defaults` in prose where the schema cannot truthfully carry them.
     *
     * @param  list<string>  $defaults
     */
    private static function withDefaultsNote(string $description, array $defaults): string
    {
        if ($defaults === []) {
            return $description;
        }

        $list = implode(', ', array_map(static fn (string $default): string => sprintf('`%s`', $default), $defaults));

        return sprintf('%s Defaults to %s.', $description, $list);
    }

    /**
     * Every include name the allow-list legalizes, in Spatie's own generation order. A bare-string
     * entry is expanded exactly as `AddsIncludesToQuery::generateIncludesFromString()` expands it — a
     * Count/Exists-suffixed name is that include alone; anything else yields its cumulative
     * relationship partials, each dot-less partial also minting its Count and Exists forms — while a
     * factory-built `AllowedInclude` legalizes only its own name. Deduped keeping first occurrence,
     * so the set is a function of the allow-list alone.
     *
     * @param  list<QbEntry>  $includes
     * @return list<string>
     */
    private static function includeValues(array $includes, QueryBuilderConfig $config): array
    {
        $values = [];
        foreach ($includes as $include) {
            foreach (self::legalizedIncludeNames($include, $config) as $name) {
                if (! in_array($name, $values, true)) {
                    $values[] = $name;
                }
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function legalizedIncludeNames(QbEntry $include, QueryBuilderConfig $config): array
    {
        if ($include->kind !== 'default') {
            return [$include->name];
        }

        // Spatie matches suffixes with Str::endsWith, which skips empty needles — so an empty
        // configured suffix neither claims a bare string nor mints suffixed forms.
        $suffixes = array_values(array_filter([$config->countSuffix, $config->existsSuffix], static fn (string $suffix): bool => $suffix !== ''));

        foreach ($suffixes as $suffix) {
            if (str_ends_with($include->name, $suffix)) {
                return [$include->name];
            }
        }

        $names = [];
        $partial = null;
        foreach (explode('.', $include->name) as $segment) {
            $partial = $partial === null ? $segment : $partial.'.'.$segment;
            $names[] = $partial;
            if (! str_contains($partial, '.')) {
                foreach ($suffixes as $suffix) {
                    $names[] = $partial.$suffix;
                }
            }
        }

        return $names;
    }

    /**
     * The comma-serialised array schema half of {@see commaListSpec()} / {@see whereInSchema()}.
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private static function commaList(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    /**
     * @return list<QueryParameterSpec>
     */
    private function fieldParameters(QueryBuilderFacts $facts, RepresentationPolicy $policy, QueryBuilderConfig $config): array
    {
        if ($facts->fields === []) {
            return [];
        }

        // Group `type.field` paths by their type prefix (a bare field groups under the empty prefix).
        $byType = [];
        foreach ($facts->fields as $field) {
            $sep = strpos($field->name, '.');
            $type = $sep === false ? '' : substr($field->name, 0, $sep);
            $column = $sep === false ? $field->name : substr($field->name, $sep + 1);
            $byType[$type][] = $column;
        }

        if ($policy->filtersDeepObject()) {
            $properties = [];
            foreach ($byType as $type => $columns) {
                $key = $type === '' ? '_' : $type;
                $properties[$key] = ['type' => 'string', 'description' => sprintf('Comma-separated fields: %s.', implode(', ', $columns))];
            }

            return [new QueryParameterSpec(
                name: $config->fields,
                schema: ['type' => 'object', 'properties' => $properties],
                description: 'Request a sparse fieldset.',
                style: 'deepObject',
                explode: true,
            )];
        }

        $specs = [];
        foreach ($byType as $type => $columns) {
            $specs[] = new QueryParameterSpec(
                name: $type === '' ? $config->fields : $config->fieldsKey($type),
                schema: ['type' => 'string'],
                description: sprintf('Comma-separated fields: %s.', implode(', ', $columns)),
            );
        }

        return $specs;
    }

    /**
     * @return list<QueryParameterSpec>
     */
    private function paginationParameters(QueryBuilderFacts $facts): array
    {
        if (! $facts->paginates) {
            return [];
        }

        // The page selector is minted once for the whole adapter, so this and the resource-collection
        // producer cannot drift apart — including on a key the call site renamed.
        $page = PaginatorPageParameter::forTerminal($facts->paginationTerminal, $facts->paginationKind, $facts->paginationArgs);

        // A size key only where the trace proved the endpoint reads one; a chain sized at the call site
        // contributes nothing beside its page key.
        $size = $facts->pageSize === null ? null : PaginatorPageParameter::size($facts->pageSize);

        return array_values(array_filter([$page, $size]));
    }

    private static function filterKindDescription(string $kind): string
    {
        return self::FILTER_DESCRIPTIONS[$kind] ?? 'Filter';
    }
}
