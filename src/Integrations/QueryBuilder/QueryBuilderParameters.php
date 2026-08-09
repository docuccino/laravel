<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * Turns recovered {@see QueryBuilderFacts} into query-parameter specs under a {@see
 * RepresentationPolicy} and the package's own {@see QueryBuilderConfig} parameter names (design
 * §Representation policies): the semantic facts are policy-independent, this class is the only place
 * the *expression* is decided — bracketed `filter[status]` params vs a single `filter` deep-object,
 * comma-string `sort`/`include` vs exploded arrays, sparse-fieldset params, pagination, and how an
 * exact filter's recovered column cast is expressed (an enum modelled as a comma-serialised array so
 * Spatie's whereIn split stays valid, a native cast as its scalar type). Pure and deterministic so
 * every branch is dataset-testable without a pipeline.
 */
final class QueryBuilderParameters
{
    private const DEFAULT_PER_PAGE = 15;

    private const WHERE_IN_NOTE = 'Accepts a comma-separated list of values (matched as `whereIn`).';

    private const NULLABLE_NOTE = 'Accepts `null` to filter for absent values.';

    /** The soft-delete filter's fixed value set (Spatie's `FiltersTrashed`). */
    private const TRASHED_VALUES = ['with', 'only'];

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
     * The bracketed-filter schema plus its serialization style: the soft-delete filter is a fixed
     * `with`/`only` enum, an enum-typed exact filter becomes a comma-serialised array (so a `whereIn`
     * list validates), a resolved column/scope/custom type becomes its scalar schema, and anything
     * else keeps the plain-string shape.
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

        $schema = $filter->columnSchema ?? ['type' => 'string'];

        return [$this->withDefault($schema, $filter), null, null];
    }

    /**
     * The deepObject property schema for a filter (description inline, no per-property style/explode).
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
            $schema = $filter->columnSchema ?? ['type' => 'string'];
        }

        $schema['description'] = $this->filterDescription($filter);
        if ($filter->example !== null) {
            $schema['example'] = $filter->example;
        }

        return $this->withDefault($schema, $filter);
    }

    /**
     * The soft-delete filter's fixed schema — a `with`/`only` string enum (never a `whereIn` array;
     * a single mode is selected).
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
            // For the enum-array modelling the default is the single value, not a wrapped list.
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

        // Terminate the lead fragment so the appended note-sentences read cleanly (note-less filters
        // keep their bare fragment, so their goldens don't churn).
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

        // The `-name` descending convention: each allowed sort admits an ascending and a `-`-prefixed
        // descending form.
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

        $perPage = new QueryParameterSpec(
            'per_page',
            ['type' => 'integer', 'default' => $facts->perPage ?? self::DEFAULT_PER_PAGE, 'minimum' => 1],
            'Items per page.',
        );

        if ($facts->paginationKind === 'cursor') {
            return [
                new QueryParameterSpec('cursor', ['type' => 'string'], 'Opaque cursor for the next/previous page.'),
                $perPage,
            ];
        }

        return [
            new QueryParameterSpec('page', ['type' => 'integer', 'default' => 1, 'minimum' => 1], 'Page number.'),
            $perPage,
        ];
    }

    private static function filterKindDescription(string $kind): string
    {
        return self::FILTER_DESCRIPTIONS[$kind] ?? 'Filter';
    }
}
