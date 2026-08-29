<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Laravel\Integrations\Support\PaginatorPageParameter;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;
use Docuccino\Laravel\Support\ListValueNames;

/**
 * Turns recovered {@see QueryBuilderFacts} into query-parameter specs. The facts themselves are
 * policy-independent; this is the only place their EXPRESSION is decided — bracketed `filter[status]`
 * params vs one `filter` deep-object, comma-serialised enum lists for sort/include and each sparse-fieldset group, pagination,
 * and how a column cast surfaces (an enum becomes a comma-serialised array so Spatie's whereIn split
 * stays valid; a native cast is just its scalar type). Names and the effective delimiter come from
 * {@see QueryBuilderConfig}; the filter/fields shapes and the enum naming policy from the
 * {@see RepresentationPolicy}; per-value prose from the entry's comment or a {@see ListValueDescriber}.
 * Pure and deterministic, so every branch is dataset-testable.
 *
 * @phpstan-type LegalizedInclude array{name: string, source: 'entry'|'partial'|'count'|'exists', base: string}
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
     * The token {@see FILTER_DESCRIPTIONS} spends on the filter's public name. Public API: a sentence
     * configured under `integrations.query_builder.filter_descriptions` may spend it too, and it is the
     * ONLY token those sentences interpolate — anything else is published verbatim.
     */
    public const FIELD_TOKEN = '%field%';

    /**
     * The vague-but-true prose for a filter whose matching lives in user code, and the degrade for a kind
     * this table doesn't know. It states that the parameter filters and on which public key, and claims
     * no match semantics — a wrong "exact match" would send a consumer's client at the wrong contract.
     */
    private const OPAQUE_DESCRIPTION = 'Filters the result set by `%field%`.';

    /**
     * Filter kind → the contract its prose states, {@see FIELD_TOKEN} standing in for the filter's PUBLIC
     * name. Never the internal column (`exact('status', 'status_code')` documents `status`): the reader
     * cannot see the codebase, and the column is not what they send. Kinds whose matching is user code's
     * carry {@see OPAQUE_DESCRIPTION} rather than invent semantics.
     *
     * A kind is the factory method the application WROTE, so the key already names the package major
     * that has it — v7's `beginsWith` and v6's `beginsWithStrict` cannot both appear in one codebase,
     * and `groupOr`/`groupAnd` exist from 7.3 on. That is why this table is read without consulting
     * `QueryBuilderConfig::$spatieMajor` while the sort/include enums are not: the evidence is the key.
     * A row whose truth depends on the installed major rather than on the spelling would need that gate.
     *
     * The closed set of kinds a document may override — {@see filterKinds()} publishes it, and
     * `QueryBuilderConfig::$filterDescriptions` is merged over it per kind.
     *
     * @var array<string, string>
     */
    private const FILTER_DESCRIPTIONS = [
        'default' => 'Substring match on `%field%`.',
        'partial' => 'Substring match on `%field%`.',
        'exact' => 'Exact match on `%field%`.',
        'beginsWith' => 'Prefix match on `%field%`.',
        'endsWith' => 'Suffix match on `%field%`.',
        // The v6 spellings of the two above, kept because the integration activates on any installed
        // major and the match they perform is the same one.
        'beginsWithStrict' => 'Prefix match on `%field%`.',
        'endsWithStrict' => 'Suffix match on `%field%`.',
        'scope' => self::OPAQUE_DESCRIPTION,
        'callback' => self::OPAQUE_DESCRIPTION,
        'custom' => self::OPAQUE_DESCRIPTION,
        // Which comparison is Spatie's `FilterOperator` argument, which the trace reads only for
        // staticness — so the direction is not ours to state, and the comparison itself is.
        'operator' => 'Compares `%field%` against the value.',
        // One key whose value every grouped member filter is applied to, joined by the conjunction.
        'groupOr' => 'Matches records where at least one of the conditions grouped under `%field%` holds.',
        'groupAnd' => 'Matches records where every condition grouped under `%field%` holds.',
        'trashed' => 'Soft-delete filter: `with` includes soft-deleted records, `only` returns only soft-deleted; omit to exclude them.',
        'belongsTo' => 'Matches records belonging to the given `%field%`.',
    ];

    /**
     * @return list<QueryParameterSpec>
     */
    public function build(QueryBuilderFacts $facts, RepresentationPolicy $policy, QueryBuilderConfig $config = new QueryBuilderConfig, ?ListValueDescriber $describer = null): array
    {
        return [
            ...$this->filterParameters($facts, $policy, $config),
            ...$this->sortParameters($facts, $policy, $config, $describer),
            ...$this->includeParameters($facts, $policy, $config, $describer),
            ...$this->fieldParameters($facts, $policy, $config, $describer),
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

    /** A filter's description: its comment (else the kind's contract sentence), plus whereIn/nullable notes. */
    private function filterDescription(QbEntry $filter, QueryBuilderConfig $config): string
    {
        // The entry's own comment describes THIS filter, so it still outranks a per-kind sentence, which
        // describes every filter of that kind.
        $base = $filter->comment ?? self::filterKindDescription($filter, $config);

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

        // Terminate the lead so the appended notes read as sentences. Every generated sentence already
        // ends itself; an author's comment is whatever they wrote.
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
    private function sortParameters(QueryBuilderFacts $facts, RepresentationPolicy $policy, QueryBuilderConfig $config, ?ListValueDescriber $describer): array
    {
        if ($facts->sorts === []) {
            return [];
        }

        $bases = self::sortBases($facts);
        $description = sprintf('Sort by: %s (prefix `-` for descending).', implode(', ', $bases));

        // An entry's own comment outranks the column's @property prose; the descending form carries
        // the same text, marked. First occurrence wins, exactly as the value dedupe does.
        $descriptions = [];
        foreach ($bases as $base) {
            $text = self::sortComment($facts, $base) ?? $describer?->column($base);
            if ($text !== null) {
                $descriptions[$base] = $text;
                $descriptions['-'.$base] = $text.' (descending)';
            }
        }

        return [self::commaListSpec($config->sort, self::sortValues($facts), $description, $config, $policy->enumNaming, $descriptions, $facts->defaultSorts, $facts->partial('sorts'))];
    }

    /**
     * The deduped stripped sort names, in allow-list order. Spatie's AllowedSort ltrim()s a leading
     * `-` off the allow-listed name, so the base is the stripped one.
     *
     * @return list<string>
     */
    private static function sortBases(QueryBuilderFacts $facts): array
    {
        $bases = [];
        foreach ($facts->sorts as $sort) {
            $name = ltrim($sort->name, '-');
            if (! in_array($name, $bases, true)) {
                $bases[] = $name;
            }
        }

        return $bases;
    }

    /**
     * Every value the sort enum lists — each base in both directions. Public because the extension
     * mints the same names to report collisions; this is the one derivation of the set.
     *
     * @return list<string>
     */
    public static function sortValues(QueryBuilderFacts $facts): array
    {
        $values = [];
        foreach (self::sortBases($facts) as $base) {
            $values[] = $base;
            $values[] = '-'.$base;
        }

        return $values;
    }

    /** The first allow-list entry's comment for a stripped sort name, else null. */
    private static function sortComment(QueryBuilderFacts $facts, string $base): ?string
    {
        foreach ($facts->sorts as $sort) {
            if (ltrim($sort->name, '-') === $base) {
                return $sort->comment;
            }
        }

        return null;
    }

    /**
     * @return list<QueryParameterSpec>
     */
    private function includeParameters(QueryBuilderFacts $facts, RepresentationPolicy $policy, QueryBuilderConfig $config, ?ListValueDescriber $describer): array
    {
        if ($facts->includes === []) {
            return [];
        }

        $names = array_map(static fn (QbEntry $i): string => $i->name, $facts->includes);
        $description = sprintf('Include related resources: %s.', implode(', ', $names));

        $values = [];
        $descriptions = [];
        foreach ($facts->includes as $include) {
            foreach (self::legalizedIncludes($include, $config) as $legal) {
                if (in_array($legal['name'], $values, true)) {
                    continue;
                }

                $values[] = $legal['name'];
                $text = self::includeDescription($legal, $include, $describer);
                if ($text !== null) {
                    $descriptions[$legal['name']] = $text;
                }
            }
        }

        return [self::commaListSpec($config->include, $values, $description, $config, $policy->enumNaming, $descriptions, partial: $facts->partial('includes'))];
    }

    /**
     * The prose for one legalized include value: the entry's own comment for the name the author
     * wrote, the relation method's docblock for any single-segment name, and the approved derived
     * line for a machine-minted Count/Exists form — explicit beats inferred beats derived.
     *
     * @param  LegalizedInclude  $legal
     */
    private static function includeDescription(array $legal, QbEntry $entry, ?ListValueDescriber $describer): ?string
    {
        return match ($legal['source']) {
            'entry' => $entry->comment ?? $describer?->include($legal['name']),
            'partial' => $describer?->include($legal['name']),
            'count' => sprintf('Count of related `%s` records.', $legal['base']),
            'exists' => sprintf('Whether related `%s` records exist.', $legal['base']),
        };
    }

    /**
     * The one comma-serialised list parameter (`form`, `explode: false`, items enumming `$values`) sort,
     * include and the bracketed fields groups share, its schema and degrades from {@see listSchema()}.
     *
     * A default (Spatie's `defaultSort`) lands on the schema only where every value is a member of the
     * emitted enum — a defaultSort needs no allow-listing, and a default violating its own schema would
     * be a lie — and only on the comma form, whose array type it matches. Anywhere else it is stated in
     * the description instead.
     *
     * @param  list<string>  $values
     * @param  array<string, string>  $descriptions  prose keyed by value
     * @param  list<string>  $defaults
     */
    private static function commaListSpec(string $name, array $values, string $description, QueryBuilderConfig $config, string $naming, array $descriptions, array $defaults = [], bool $partial = false): QueryParameterSpec
    {
        [$schema, $description, $comma] = self::listSchema($values, $description, $config, $naming, $descriptions, $partial);

        $onSchema = $comma && $defaults !== [] && array_diff($defaults, $values) === [];
        if ($onSchema) {
            // The defaultSort chain as written, an array because several defaults compose.
            $schema['default'] = $defaults;
        }

        $description = self::withDefaultsNote($description, $onSchema ? [] : $defaults);

        return $comma
            ? new QueryParameterSpec($name, $schema, $description, style: 'form', explode: false)
            : new QueryParameterSpec($name, $schema, $description);
    }

    /**
     * The schema half of the comma list, shared with each sparse-fieldset group — every degrade
     * identical: the vague-true plain string wherever {@see QueryBuilderConfig::mintsNames()} says no
     * enum reaches the document (below v7, whose minting grammar differed and whose config keys we
     * don't read; or under a delimiter the comma-array form would misdescribe) and where `$partial`
     * says the trace lost an entry of THIS list, so the recovered values are true but their set is
     * not; the separator named in prose where it is not the comma; the single-value item schema when
     * nothing splits. A degraded string has no enum to decorate, and the collision report reads the
     * same predicates.
     *
     * @param  list<string>  $values
     * @param  array<string, string>  $descriptions  prose keyed by value
     * @return array{0: array<string, mixed>, 1: string, 2: bool} schema, description, whether the comma form applies
     */
    private static function listSchema(array $values, string $description, QueryBuilderConfig $config, string $naming, array $descriptions, bool $partial = false): array
    {
        if (! $config->mintsNames() || $partial) {
            // A pre-v7 install configured the delimiter another way, so there is no separator to name.
            $note = $config->legacyPackage() ? '' : self::separatorNote($config);

            return [['type' => 'string'], $description.$note, false];
        }

        $items = EnumDecoration::apply(
            ['type' => 'string', 'enum' => $values],
            $naming,
            ListValueNames::names($values),
            $descriptions,
        );

        return $config->splitsOnComma()
            ? [self::commaList($items), $description, true]
            : [$items, $description, false];
    }

    /** The separator named in prose, where values split on something other than the comma the array form serialises. */
    private static function separatorNote(QueryBuilderConfig $config): string
    {
        return $config->splitsOnComma() || $config->delimiter === ''
            ? ''
            : sprintf(' Values are separated by `%s`.', $config->delimiter);
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
     * Every include name the allow-list legalizes, in Spatie's own generation order — the flat form
     * of {@see legalizedIncludes()}, deduped keeping first occurrence so the set is a function of the
     * allow-list alone. Public because the extension mints the same names to report collisions; this
     * is the one derivation of the set.
     *
     * @param  list<QbEntry>  $includes
     * @return list<string>
     */
    public static function includeValues(array $includes, QueryBuilderConfig $config): array
    {
        $values = [];
        foreach ($includes as $include) {
            foreach (self::legalizedIncludes($include, $config) as $legal) {
                if (! in_array($legal['name'], $values, true)) {
                    $values[] = $legal['name'];
                }
            }
        }

        return $values;
    }

    /**
     * What one allow-list entry legalizes, with each name's provenance — the description sources
     * hang off it. A bare-string entry is expanded exactly as Spatie's
     * `AddsIncludesToQuery::generateIncludesFromString()` expands it: a Count/Exists-suffixed name
     * is that include alone; anything else yields its cumulative relationship partials, each
     * dot-less partial also minting its Count and Exists forms. A factory-built `AllowedInclude`
     * legalizes only its own name.
     *
     * @return list<LegalizedInclude>
     */
    private static function legalizedIncludes(QbEntry $include, QueryBuilderConfig $config): array
    {
        if ($include->kind !== 'default') {
            return [['name' => $include->name, 'source' => 'entry', 'base' => $include->name]];
        }

        // Spatie matches suffixes with Str::endsWith, which skips empty needles — so an empty
        // configured suffix neither claims a bare string nor mints suffixed forms.
        $suffixes = [];
        if ($config->countSuffix !== '') {
            $suffixes[$config->countSuffix] = 'count';
        }
        if ($config->existsSuffix !== '' && ! isset($suffixes[$config->existsSuffix])) {
            $suffixes[$config->existsSuffix] = 'exists';
        }

        foreach (array_keys($suffixes) as $suffix) {
            if (str_ends_with($include->name, $suffix)) {
                return [['name' => $include->name, 'source' => 'entry', 'base' => $include->name]];
            }
        }

        $legal = [];
        $partial = null;
        foreach (explode('.', $include->name) as $segment) {
            $partial = $partial === null ? $segment : $partial.'.'.$segment;
            // The written name is the author's own; a shorter partial is Spatie's.
            $legal[] = ['name' => $partial, 'source' => $partial === $include->name ? 'entry' : 'partial', 'base' => $partial];
            if (! str_contains($partial, '.')) {
                foreach ($suffixes as $suffix => $source) {
                    $legal[] = ['name' => $partial.$suffix, 'source' => $source, 'base' => $partial];
                }
            }
        }

        return $legal;
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
     * One parameter per recovered fields type-group, each an enum of that group's allow-listed
     * columns — the same closed-set rule and the same degrades as sort/include ({@see listSchema()}).
     * Spatie validates a request key against the qualifier written in the allow-list
     * (`ensureAllFieldsExist()` prepends and diffs, with no case conversion of its own), which is
     * exactly the grouping here; the bare group is also accepted unbracketed (`fields=id` parses to
     * the subject table).
     *
     * Per-value prose: the entry's own comment, and for the bare (subject-model) group the column's
     * `@property` docblock. A `type.` prefix names a related table this pass can't statically map to
     * a model, so those groups stay undescribed rather than guessed at.
     *
     * @return list<QueryParameterSpec>
     */
    private function fieldParameters(QueryBuilderFacts $facts, RepresentationPolicy $policy, QueryBuilderConfig $config, ?ListValueDescriber $describer): array
    {
        if ($facts->fields === []) {
            return [];
        }

        $groups = self::fieldValues($facts->fields);

        if ($policy->filtersDeepObject()) {
            $properties = [];
            foreach ($groups as $type => $columns) {
                $prose = sprintf('Fields to return: %s.', implode(', ', $columns));
                [$schema, $prose] = self::listSchema($columns, $prose, $config, $policy->enumNaming, self::fieldDescriptions($facts, $type, $columns, $describer), $facts->partial('fields'));
                $schema['description'] = $prose;
                $properties[$type === '' ? '_' : $type] = $schema;
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
        foreach ($groups as $type => $columns) {
            $specs[] = self::commaListSpec(
                $type === '' ? $config->fields : $config->fieldsKey($type),
                $columns,
                sprintf('Fields to return: %s.', implode(', ', $columns)),
                $config,
                $policy->enumNaming,
                self::fieldDescriptions($facts, $type, $columns, $describer),
                partial: $facts->partial('fields'),
            );
        }

        return $specs;
    }

    /**
     * A group per type prefix (bare fields under the empty prefix), columns deduped keeping first, in
     * allow-list order. Split on the LAST dot — Spatie's request parsing takes the field as
     * `afterLast('.')` and everything before it as the table key. Public because the extension mints
     * the same member names to report collisions; this is the one derivation of the groups.
     *
     * @param  list<QbEntry>  $fields
     * @return array<string, list<string>>
     */
    public static function fieldValues(array $fields): array
    {
        $groups = [];
        foreach ($fields as $field) {
            [$type, $column] = self::fieldParts($field->name);
            if (! in_array($column, $groups[$type] ?? [], true)) {
                $groups[$type][] = $column;
            }
        }

        return $groups;
    }

    /**
     * Per-column prose for one fields group: the entry's comment, else — bare group only — the
     * column's `@property` docblock. First occurrence wins, exactly as the value dedupe does.
     *
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private static function fieldDescriptions(QueryBuilderFacts $facts, string $type, array $columns, ?ListValueDescriber $describer): array
    {
        $comments = [];
        foreach ($facts->fields as $field) {
            [$fieldType, $column] = self::fieldParts($field->name);
            if ($fieldType === $type && $field->comment !== null && ! isset($comments[$column])) {
                $comments[$column] = $field->comment;
            }
        }

        $descriptions = [];
        foreach ($columns as $column) {
            $text = $comments[$column] ?? ($type === '' ? $describer?->column($column) : null);
            if ($text !== null) {
                $descriptions[$column] = $text;
            }
        }

        return $descriptions;
    }

    /**
     * @return array{0: string, 1: string} type prefix ('' for a bare field) and column
     */
    private static function fieldParts(string $name): array
    {
        $sep = strrpos($name, '.');

        return $sep === false ? ['', $name] : [substr($name, 0, $sep), substr($name, $sep + 1)];
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

    /**
     * The kind's contract sentence, with the public name substituted. A document's configured sentence
     * for this kind wins over {@see FILTER_DESCRIPTIONS}; every kind it does not name keeps its default,
     * which is what makes the setting a merge rather than a replacement. A configured sentence spends
     * {@see FIELD_TOKEN} exactly as a built-in one does, and one carrying no token is published as
     * written — a constant sentence is legal (`trashed` ships one).
     */
    private static function filterKindDescription(QbEntry $filter, QueryBuilderConfig $config): string
    {
        $template = $config->filterDescriptions[$filter->kind]
            ?? self::FILTER_DESCRIPTIONS[$filter->kind]
            ?? self::OPAQUE_DESCRIPTION;

        return str_replace(self::FIELD_TOKEN, $filter->name, $template);
    }

    /**
     * Every filter kind {@see FILTER_DESCRIPTIONS} describes — the source of truth for what a document
     * may override, so the config diagnostic and the catalogue test read the table rather than a copy.
     *
     * @return list<string>
     */
    public static function filterKinds(): array
    {
        return array_keys(self::FILTER_DESCRIPTIONS);
    }
}
