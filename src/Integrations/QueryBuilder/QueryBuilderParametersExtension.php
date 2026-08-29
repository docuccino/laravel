<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use BackedEnum;
use Closure;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Provenance\Source;
use Docuccino\Laravel\Integrations\Eloquent\CastSchema;
use Docuccino\Laravel\Support\IgnoredResponses;
use Docuccino\Laravel\Support\ListValueNames;
use ReflectionClass;

/**
 * Documents a `spatie/laravel-query-builder` list endpoint: a {@see QueryBuilderTraceVisitor} recovers the
 * subject model, allow-lists and pagination from the action and from the constructor of every builder
 * subclass it is handed, then each filter is typed by its column's cast and the facts become query
 * parameters under the document's representation policy and the package's own parameter names.
 *
 * Writes at the integration layer, so docblocks and attributes still override.
 */
// Priority beats the attribute parameter layer so a deepObject container emitted here already exists
// when `#[QueryParameter('filter[child]')]` patches one of its properties.
#[ExtensionOrder(priority: Priorities::EARLY)]
final class QueryBuilderParametersExtension implements OperationExtension
{
    public function __construct(
        private readonly QueryBuilderConfig $config = new QueryBuilderConfig,
        private readonly QueryBuilderParameters $builder = new QueryBuilderParameters,
        private readonly FilterColumnResolver $columns = new FilterColumnResolver,
        private readonly ScopeParameterResolver $scopes = new ScopeParameterResolver,
        private readonly CustomFilterReader $customFilters = new CustomFilterReader,
        private readonly ResponseDraftApplier $errors = new ResponseDraftApplier,
        /** The app's vendor boundary, supplied by the service provider — see {@see QbBuilderRoots}. */
        private readonly ?Closure $isVendorFile = null,
    ) {}

    private const INVALID_QUERY = 'Spatie\\QueryBuilder\\Exceptions\\InvalidQuery';

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $visitor = new QueryBuilderTraceVisitor(
            customTerminals: $this->customTerminals($context),
            paths: $context->pathResolver,
        );
        // The action first — the outermost paginating terminal has to be the one the action itself writes —
        // then each injected builder's constructor, into the same facts.
        $context->trace($visitor);
        $this->traceInjectedBuilders($visitor, $context);

        // The page-size clamp's own file (and its parents/traits) — the trace reports what it descended
        // into, but the recovery also reflects, and a fact is keyed on where it was WRITTEN.
        $context->recordDependencyFiles($visitor->dependencyFiles());

        $facts = $visitor->facts;
        if ($facts->isEmpty()) {
            $this->reportUnresolved($facts, $context);

            return;
        }

        $metadata = $this->enrichFilters($facts, $context);
        $describer = $facts->subjectModel !== null && $metadata !== null
            ? new ListValueDescriber($facts->subjectModel, $metadata)
            : null;

        $contribution = Contribution::integration('query-builder', $context->actionSource());

        foreach ($this->builder->build($facts, $context->representation(), $this->effectiveConfig($context), $describer) as $spec) {
            $spec->applyTo($operation->parameter('query', $spec->name), $contribution);
        }

        $this->reportUnresolved($facts, $context);
        $this->reportNoAllowLists($facts, $context);
        $this->reportDefaultConfig($context);
        $this->reportLegacyPackage($facts, $context);
        $this->reportEnumNameCollisions($facts, $context);
        $this->documentStrictModeError($operation, $context);
    }

    /**
     * Traces the constructor of every builder subclass the action is handed ({@see QbBuilderRoots}) with the
     * SAME visitor, so a query object that configures itself in its constructor contributes to the same
     * facts. {@see RouteContext::traceFrom()} records each walk's files, so editing the query class
     * invalidates the fragment.
     */
    private function traceInjectedBuilders(QueryBuilderTraceVisitor $visitor, RouteContext $context): void
    {
        foreach (QbBuilderRoots::forAction($context->actionRef, $this->isVendorFile) as $root) {
            $context->traceFrom($root, $visitor);
        }
    }

    /**
     * In strict mode (the package default) an unknown filter/sort/include raises `InvalidQuery` → 400.
     * A synthetic 400 goes through the resolved exception→response chain so its body matches the
     * document's error style. Skipped when strict mode is off, `error_responses => 'none'`, or the route
     * drops the status the chain answers at ({@see IgnoredResponses}).
     */
    private function documentStrictModeError(OperationDraft $operation, RouteContext $context): void
    {
        if (! $this->config->strict || $context->document->errorResponses === 'none') {
            return;
        }

        $throw = new ThrownException(self::INVALID_QUERY, 400, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
        $source = $context->actionSource();
        $source = $source === null ? null : new Source($source->file, $source->line, 'query-builder:strict-mode');

        $mapped = IgnoredResponses::mapThrow($context, $throw);
        if ($mapped !== null) {
            $this->errors->apply($operation, $mapped->draft, 'integration:query-builder', $source);
        }
    }

    /**
     * Enriches each filter with the subject model's cast for its column. The model's reflected shape and
     * any enum-cast file join the fragment-cache key, so editing either invalidates the warm fragment.
     */
    private function enrichFilters(QueryBuilderFacts $facts, RouteContext $context): ?ClassMetadata
    {
        if ($facts->subjectModel === null) {
            return null;
        }

        $model = $facts->subjectModel;
        $metadata = $context->engine->classMetadata(new ClassRef($model));
        $context->recordDependencyFiles($metadata->dependencyFiles);

        $facts->filters = array_map(
            fn (QbEntry $filter): QbEntry => $this->enrichFilter($filter, $model, $context),
            $facts->filters,
        );

        // The same metadata answers the sort values' @property prose downstream.
        return $metadata;
    }

    /**
     * Types one filter off the subject model per kind: `exact`/static `operator`/`callback` off the
     * resolved column's cast, a `scope` off its scope method's value parameter, a `custom` off its class
     * attribute or `__invoke` body, a project factory off the custom filter class its body wraps — else
     * its own attribute, else its written column ({@see enrichProjectFactory}). A partial or bare-string
     * filter over an enum column is never enum-typed — a substring match isn't an enum member — and gets
     * a nudge towards `exact` instead.
     */
    private function enrichFilter(QbEntry $filter, string $model, RouteContext $context): QbEntry
    {
        // A project factory's own file is a dependency (its identity shapes the typing). A backed-enum
        // argument types the value off that enum as a scalar — one value compared, not the whereIn array
        // `exact` uses.
        if ($filter->factoryClass !== null) {
            $this->recordFactoryFile($filter, $context);
        }
        if ($filter->factoryEnum !== null) {
            return $this->applyColumn($filter, $this->enumColumn($filter->factoryEnum), $context, asArray: false);
        }

        if (in_array($filter->kind, ['default', 'partial'], true)) {
            $this->nudgePartialOnEnum($filter, $model, $context);

            return $filter;
        }

        return match ($filter->kind) {
            'exact', 'operator', 'callback' => $filter->typeColumn !== null
                ? $this->applyColumn($filter, $this->columns->resolve($model, $filter->typeColumn), $context, asArray: $filter->kind === 'exact')
                : $filter,
            'scope' => $this->applyColumn($filter, $this->scopes->resolve($model, $filter->name), $context, asArray: false),
            'custom' => $this->enrichCustom($filter, $model, $context),
            default => $this->enrichProjectFactory($filter, $model, $context),
        };
    }

    /**
     * A project-factory entry, in precedence order: the custom filter class its body's fold recovered
     * answers exactly like `AllowedFilter::custom` (class attribute, then `__invoke` column); a factory
     * that could not fold may still declare a class-level `#[QueryParameter]` of its own — the
     * `UuidFilter::allowed()` idiom with an unfoldable body. Failing both, the written column argument —
     * else the filter's own name — types off the model cast (the `$column ?? $key` idiom); a name that
     * isn't a column stays a string. An unfoldable body is never guessed at: it contributes only what
     * its class declares.
     */
    private function enrichProjectFactory(QbEntry $filter, string $model, RouteContext $context): QbEntry
    {
        if ($filter->filterClass !== null) {
            return $this->enrichCustom($filter, $model, $context);
        }

        if ($filter->factoryClass !== null) {
            // The factory file itself is already recorded by recordFactoryFile().
            $facts = $this->customFilters->read($filter->factoryClass);
            if ($facts->attribute !== null) {
                return $this->applyCustomAttribute($filter, $facts->attribute, $context);
            }
        }

        return $filter->typeColumn !== null
            ? $this->applyColumn($filter, $this->columns->resolve($model, $filter->typeColumn), $context, asArray: false)
            : $filter;
    }

    /** A column for a backed-enum class-string recovered from a project-factory argument. */
    private function enumColumn(string $enumClass): FilterColumn
    {
        $file = EnumReflection::file($enumClass);

        return FilterColumn::enum($enumClass, $file !== null ? [$file] : []);
    }

    private function recordFactoryFile(QbEntry $filter, RouteContext $context): void
    {
        if ($filter->factoryClass === null || ! class_exists($filter->factoryClass)) {
            return;
        }

        $file = (new ReflectionClass($filter->factoryClass))->getFileName();
        if ($file !== false) {
            $context->recordDependencyFiles([$file]);
        }
    }

    /**
     * Applies a resolved column onto a filter: an enum gives backing values + `x-enumDescriptions`
     * (rendered as a whereIn array only when `$asArray`), a native cast gives its scalar type, and a
     * `none` leaves the filter a plain string.
     */
    private function applyColumn(QbEntry $filter, FilterColumn $column, RouteContext $context, bool $asArray): QbEntry
    {
        // Whatever the outcome — an enum's file, a foreign-key hop's related model, a refusal that read
        // files to refuse — the resolution's inputs key the fragment.
        $context->recordDependencyFiles($column->dependencyFiles);

        if ($column->isEnum() && $column->enum !== null) {
            $schema = $context->converter()->convert(new EnumT($column->enum, EnumReflection::names($column->enum)));

            return $filter->withColumn($schema, enumTyped: $asArray);
        }

        if ($column->isScalar() && $column->scalarSchema !== null) {
            return $filter->withColumn($column->scalarSchema, enumTyped: false);
        }

        return $filter;
    }

    /**
     * A class-level `#[QueryParameter]` on the filter class is the explicit override and wins; otherwise
     * the column its `__invoke` body filters on types the value; failing that, the declared internal
     * name — what Spatie passes a generic filter as `$property`, the filter's own name when none was
     * written — resolves against the model's casts like any other column.
     */
    private function enrichCustom(QbEntry $filter, string $model, RouteContext $context): QbEntry
    {
        if ($filter->filterClass === null) {
            return $filter;
        }

        $facts = $this->customFilters->read($filter->filterClass);
        if ($facts->file !== null) {
            $context->recordDependencyFiles([$facts->file]);
        }

        if ($facts->attribute !== null) {
            return $this->applyCustomAttribute($filter, $facts->attribute, $context);
        }

        // A literal column in the body is the ground truth when there is one — a filter is free to
        // ignore the $property it was handed.
        $column = $facts->column ?? $filter->column();

        return $this->applyColumn($filter, $this->columns->resolve($model, $column), $context, asArray: false);
    }

    /**
     * Folds the attribute's schema/description/format/default/example into the filter. Its `name` is
     * ignored — the parameter name is always the `AllowedFilter` name. A route-level attribute still
     * overrides this downstream.
     *
     * The class attribute speaks for EVERY call site, so anything the entry itself says is the narrower
     * claim and wins: a comment above the entry over `description`, a chained `->default()` over
     * `default`. Not a contradiction of the fallback < inference < integration < docblock < attribute
     * ladder — both facts arrive here, at the integration layer, and this resolves them within it.
     * `type`/`format`/`example` have no per-entry rival, so the attribute is simply the only claim.
     */
    private function applyCustomAttribute(QbEntry $filter, QueryParameter $attribute, RouteContext $context): QbEntry
    {
        $default = is_scalar($attribute->default) ? $attribute->default : null;
        // Passing null preserves what the entry carries, so the narrower claim wins by not being offered
        // a replacement.
        $overridesDefault = $default !== null && ! $filter->hasDefault;

        // Description/default/example are type-independent, so set them first and let the type supply
        // the schema afterwards — applyColumn preserves them.
        $filter = $filter->withColumn(
            null,
            enumTyped: false,
            comment: $filter->comment === null ? $attribute->description : null,
            hasDefault: $overridesDefault,
            default: $overridesDefault ? $default : null,
            example: $attribute->example,
        );

        if ($attribute->type !== null) {
            $filter = $this->applyColumn($filter, $this->attributeColumn($attribute->type), $context, asArray: false);
        }

        // After the type, so an explicit format wins over one the type implied — mirroring the
        // route-level attribute layer, so the one attribute means one thing wherever it sits. Alone, it
        // rides on the filter's base string schema (a filter value is a string on the wire).
        if ($attribute->format !== null) {
            $schema = $filter->columnSchema ?? ['type' => 'string'];
            $schema['format'] = $attribute->format;
            $filter = $filter->withColumn($schema, enumTyped: $filter->enumTyped);
        }

        return $filter;
    }

    /**
     * A `#[QueryParameter(type: …)]` string read as a column: a backed-enum class-string gives the enum,
     * a scalar name goes through the cast table, anything else stays untyped. Deliberately a subset of
     * the attribute-layer type grammar — a custom filter's value is a scalar or an enum, nothing richer.
     */
    private function attributeColumn(string $type): FilterColumn
    {
        if (enum_exists($type) && is_subclass_of($type, BackedEnum::class)) {
            $file = EnumReflection::file($type);

            return FilterColumn::enum($type, $file !== null ? [$file] : []);
        }

        $schema = CastSchema::forCast($type);

        return $schema === null ? FilterColumn::none() : FilterColumn::scalar($schema);
    }

    /** Nudges a partial filter over an enum-cast column towards `exact`, which can document its values. */
    private function nudgePartialOnEnum(QbEntry $filter, string $model, RouteContext $context): void
    {
        $column = $this->columns->resolve($model, $filter->column());
        // Recorded before the enum check: whether the nudge fires at all is a fact of these files.
        $context->recordDependencyFiles($column->dependencyFiles);
        if (! $column->isEnum()) {
            return;
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'query-builder.partial-on-enum',
            message: sprintf('Filter "%s" is a partial match over an enum-cast column; its documented values cannot be enumerated.', $filter->name),
            routeSignature: $context->route->signature(),
            help: sprintf('Use AllowedFilter::exact(\'%s\') for exact matching so the enum\'s values are documented.', $filter->name),
        ));
    }

    private function reportUnresolved(QueryBuilderFacts $facts, RouteContext $context): void
    {
        foreach ($facts->unresolved as $expression) {
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Warning,
                code: 'query-builder.unresolved-entry',
                message: sprintf('Could not statically resolve a Query Builder allow-list entry (%s); it is omitted from the docs.', $expression),
                routeSignature: $context->route->signature(),
                help: 'Use a literal value or a factory call (e.g. AllowedFilter::exact(\'status\')) so it can be recovered.',
            ));
        }
    }

    /**
     * A paginating terminal was reached but not one allow-list entry turned up, recovered or unresolved
     * — usually the chain lives behind an indirection the trace couldn't follow. Names the action so the
     * loss isn't silent.
     *
     * Silent, though, once a `defaultSort()` turned up: that is a chain call where an allow-list would
     * sit, so empty allow-lists beside it are the endpoint's truth rather than a recovery miss. Only a
     * chain call counts — the subject model, the terminal and its arguments are all readable without
     * descending into the chain at all.
     */
    private function reportNoAllowLists(QueryBuilderFacts $facts, RouteContext $context): void
    {
        if (! $facts->paginates
            || $facts->filters !== [] || $facts->sorts !== [] || $facts->includes !== [] || $facts->fields !== []
            || $facts->unresolved !== []
            || $facts->defaultSorts !== []
        ) {
            return;
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'query-builder.no-allowlists-recovered',
            message: sprintf('A paginating Query Builder terminal was reached in %s, but no allow-lists and no default sort were recovered from the chain.', $context->actionLabel()),
            routeSignature: $context->route->signature(),
            help: 'If this endpoint offers filters/sorts, declare them via allowedFilters()/allowedSorts() somewhere the trace reaches: a method returning your QueryBuilder subclass is followed, and so is the constructor of a QueryBuilder subclass the action is type-hinted on (type-hint the subclass itself, not an interface or the base builder). Otherwise this is expected.',
        ));
    }

    /**
     * The sort, include and fields value enums encode v7's minting grammar, so an older install
     * degrades all three to plain strings ({@see QueryBuilderParameters::listSchema()}) — said only
     * where one of those lists was actually recovered, per route the way {@see reportDefaultConfig()}
     * is.
     */
    private function reportLegacyPackage(QueryBuilderFacts $facts, RouteContext $context): void
    {
        if (! $this->config->legacyPackage()
            || ($facts->sorts === [] && $facts->includes === [] && $facts->fields === [])
        ) {
            return;
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'query-builder.legacy-package-version',
            message: 'spatie/laravel-query-builder below v7 is installed, so the sort/include/fields allow-lists are documented as plain strings rather than value enums.',
            routeSignature: $context->route->signature(),
            help: 'Upgrade to spatie/laravel-query-builder ^7 to document the sort/include/fields allow-lists as enums.',
        ));
    }

    /**
     * Two values minting one SDK member name were published under strict value-derived names instead
     * — fires only where the author can act (rename an allow-list entry), which is also the only
     * place the collision can arise. Silent wherever {@see QueryBuilderConfig::mintsNames()} says the
     * lists degraded to plain strings: no enum was published, so no name was either.
     */
    private function reportEnumNameCollisions(QueryBuilderFacts $facts, RouteContext $context): void
    {
        if (! $this->config->mintsNames()) {
            return;
        }

        // Only the lists that published an enum: one whose recovery was partial widened to a plain
        // string, exactly as the config-driven degrades do, so it minted no names either.
        $lists = [];
        if (! $facts->partial('sorts')) {
            $lists[$this->config->sort] = QueryBuilderParameters::sortValues($facts);
        }
        if (! $facts->partial('includes')) {
            $lists[$this->config->include] = QueryBuilderParameters::includeValues($facts->includes, $this->config);
        }
        if (! $facts->partial('fields')) {
            foreach (QueryBuilderParameters::fieldValues($facts->fields) as $type => $columns) {
                $lists[$type === '' ? $this->config->fields : $this->config->fieldsKey($type)] = $columns;
            }
        }

        foreach ($lists as $parameter => $values) {
            $collisions = ListValueNames::collisions($values);
            if ($collisions === []) {
                continue;
            }

            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'query-builder.enum-name-collision',
                message: sprintf(
                    'Values %s of the "%s" parameter would share one SDK enum member name, so distinct value-derived names were published instead.',
                    implode(', ', array_map(static fn (string $value): string => sprintf('"%s"', $value), $collisions)),
                    $parameter,
                ),
                routeSignature: $context->route->signature(),
                help: 'Rename one of the colliding allow-list entries so each value mints its own member name.',
            ));
        }
    }

    private function reportDefaultConfig(RouteContext $context): void
    {
        if ($this->config->recovered) {
            return;
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'query-builder.default-config',
            message: 'The query-builder config was not readable; documented parameter names use the package defaults (filter/sort/include/fields).',
            routeSignature: $context->route->signature(),
            help: 'Publish the config (php artisan vendor:publish --tag=query-builder-config) so custom parameter names are reflected in the docs.',
        ));
    }

    /**
     * @return list<string>
     */
    private function customTerminals(RouteContext $context): array
    {
        $terminals = $context->document->integration('query_builder')['pagination_terminals'] ?? null;

        return is_array($terminals) ? array_values(array_filter($terminals, 'is_string')) : [];
    }

    /**
     * The package config the provider recovered, plus this DOCUMENT's own filter-description overrides —
     * recovered here rather than in the provider because the bag is per-document, exactly like
     * {@see customTerminals()}. It rides the document's `configHash`, so a changed sentence retires
     * warm fragments on its own.
     */
    private function effectiveConfig(RouteContext $context): QueryBuilderConfig
    {
        return $this->config->withFilterDescriptions(
            $context->document->integration('query_builder')['filter_descriptions'] ?? null,
        );
    }
}
