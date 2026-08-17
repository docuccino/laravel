<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Laravel\Integrations\Support\PaginatorPageParameter;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * Turns recovered {@see QueryBuilderFacts} into query-parameter specs. The facts themselves are
 * policy-independent; this is the only place their EXPRESSION is decided — bracketed `filter[status]`
 * params vs one `filter` deep-object, comma strings vs exploded arrays, sparse fieldsets, pagination,
 * and how a column cast surfaces (an enum becomes a comma-serialised array so Spatie's whereIn split
 * stays valid; a native cast is just its scalar type). Names come from {@see QueryBuilderConfig}, shapes
 * from the {@see RepresentationPolicy}. Pure and deterministic, so every branch is dataset-testable.
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
            ...$this->sortParameters($facts, $policy, $config),
            ...$this->includeParameters($facts, $policy, $config),
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
                $properties[$filter->name] = $this->filterProperty($filter);
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
            [$schema, $style, $explode] = $this->filterSchema($filter);
            $specs[] = new QueryParameterSpec(
                name: $config->filterKey($filter->name),
                schema: $schema,
                description: $this->filterDescription($filter),
                style: $style,
                explode: $explode,
                example: $filter->example,
            );
        }

        return $specs;
    }

    /**
     * `[schema, style, explode]` for a bracketed filter: the soft-delete filter is a fixed enum, an
     * enum-typed one a comma-serialised array so a `whereIn` list validates, a resolved column its
     * scalar schema, everything else {@see schemaWithoutColumn()}.
     *
     * @return array{0: array<string, mixed>, 1: string|null, 2: bool|null}
     */
    private function filterSchema(QbEntry $filter): array
    {
        if ($filter->kind === 'trashed') {
            return [$this->withDefault(self::trashedSchema(), $filter), null, null];
        }

        if ($filter->enumTyped && $filter->columnSchema !== null) {
            $schema = ['type' => 'array', 'items' => $filter->columnSchema];

            return [$this->withDefault($schema, $filter), 'form', false];
        }

        $schema = $filter->columnSchema ?? self::schemaWithoutColumn($filter);

        return [$this->withDefault($schema, $filter), null, null];
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
    private function filterProperty(QbEntry $filter): array
    {
        if ($filter->kind === 'trashed') {
            $schema = self::trashedSchema();
        } elseif ($filter->enumTyped && $filter->columnSchema !== null) {
            $schema = ['type' => 'array', 'items' => $filter->columnSchema];
        } else {
            $schema = $filter->columnSchema ?? self::schemaWithoutColumn($filter);
        }

        $schema['description'] = $this->filterDescription($filter);
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
    private function filterDescription(QbEntry $filter): string
    {
        $base = $filter->comment ?? self::filterKindDescription($filter->kind);

        $notes = [];
        if ($filter->enumTyped) {
            $notes[] = self::WHERE_IN_NOTE;
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
     * @return list<QueryParameterSpec>
     */
    private function sortParameters(QueryBuilderFacts $facts, RepresentationPolicy $policy, QueryBuilderConfig $config): array
    {
        if ($facts->sorts === []) {
            return [];
        }

        // Spatie's `-name` convention: every allowed sort has an ascending and a descending form.
        $values = [];
        foreach ($facts->sorts as $sort) {
            $values[] = $sort->name;
            $values[] = '-'.$sort->name;
        }

        $names = array_map(static fn (QbEntry $s): string => $s->name, $facts->sorts);
        $description = sprintf('Sort by: %s (prefix `-` for descending).', implode(', ', $names));

        if ($policy->listsAsArray()) {
            $schema = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $values]];
            if ($facts->defaultSorts !== []) {
                $schema['default'] = $facts->defaultSorts;
            }

            return [new QueryParameterSpec($config->sort, $schema, $description, style: 'form', explode: false)];
        }

        $schema = ['type' => 'string'];
        if ($facts->defaultSorts !== []) {
            $schema['default'] = implode(',', $facts->defaultSorts);
        }

        return [new QueryParameterSpec($config->sort, $schema, $description)];
    }

    /**
     * @return list<QueryParameterSpec>
     */
    private function includeParameters(QueryBuilderFacts $facts, RepresentationPolicy $policy, QueryBuilderConfig $config): array
    {
        if ($facts->includes === []) {
            return [];
        }

        $names = array_map(static fn (QbEntry $i): string => $i->name, $facts->includes);
        $description = sprintf('Include related resources: %s.', implode(', ', $names));

        if ($policy->listsAsArray()) {
            return [new QueryParameterSpec(
                $config->include,
                ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $names]],
                $description,
                style: 'form',
                explode: false,
            )];
        }

        return [new QueryParameterSpec($config->include, ['type' => 'string'], $description)];
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

        return $page === null ? [] : [$page];
    }

    private static function filterKindDescription(string $kind): string
    {
        return self::FILTER_DESCRIPTIONS[$kind] ?? 'Filter';
    }
}
