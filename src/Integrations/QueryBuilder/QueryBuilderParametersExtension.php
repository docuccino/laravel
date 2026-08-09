<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use BackedEnum;
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
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Provenance\Source;
use Docuccino\Laravel\Integrations\Eloquent\CastSchema;
use ReflectionClass;

/**
 * Documents a `spatie/laravel-query-builder` list endpoint (design §Phase 4 — Query Builder). It
 * traces the action ({@see RouteContext::trace()}, so the walk's dependency files join the fragment
 * cache key) with a {@see QueryBuilderTraceVisitor} that recovers the subject model, the allow-lists
 * (with internal column names + chained `->default()`/`->nullable()` modifiers + leading comments) and
 * pagination through ANY chain depth (the two-deep `ListQueryBuilder::for()` helper pattern). Exact
 * filters whose recovered column carries a model cast are then enriched with the cast's typed schema —
 * an enum's backing values (through the shared enum machinery, so `#[CaseDescription]` prose lands as
 * `x-enumDescriptions`) or a native cast type — before the facts are expressed as query parameters
 * under the document's {@see RepresentationPolicy} and the package's own parameter names. Un-foldable
 * entries degrade to warning diagnostics naming the exact expression — never a silent drop. Writes at
 * the integration layer, so docblocks/attributes still override.
 */
// Runs before the attribute parameter layer (priority > its default) so a deepObject container this
// integration emits already exists when `#[QueryParameter('filter[child]')]` patches its property.
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
    ) {}

    private const INVALID_QUERY = 'Spatie\\QueryBuilder\\Exceptions\\InvalidQuery';

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $visitor = new QueryBuilderTraceVisitor(customTerminals: $this->customTerminals($context));
        $context->trace($visitor);

        $facts = $visitor->facts;
        if ($facts->isEmpty()) {
            $this->reportUnresolved($facts, $context);

            return;
        }

        $this->enrichFilters($facts, $context);

        $contribution = Contribution::integration('query-builder', $context->actionSource());

        foreach ($this->builder->build($facts, $context->representation(), $this->config) as $spec) {
            $spec->applyTo($operation->parameter('query', $spec->name), $contribution);
        }

        $this->reportUnresolved($facts, $context);
        $this->reportNoAllowLists($facts, $context);
        $this->reportDefaultConfig($context);
        $this->documentStrictModeError($operation, $context);
    }

    /**
     * Under strict mode (the package default), an unknown filter/sort/include raises an
     * `InvalidQuery` (HTTP 400). Document it on any QB operation by running a synthetic 400 through the
     * resolved exception→response chain, so the body matches the document's error style. Skipped when
     * strict mode is off or `error_responses => 'none'`.
     */
    private function documentStrictModeError(OperationDraft $operation, RouteContext $context): void
    {
        if (! $this->config->strict || $context->document->errorResponses === 'none') {
            return;
        }

        $throw = new ThrownException(self::INVALID_QUERY, 400, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
        $source = $context->actionSource();
        $source = $source === null ? null : new Source($source->file, $source->line, 'query-builder:strict-mode');

        $mapped = $context->mapThrow($throw);
        if ($mapped !== null) {
            $this->errors->apply($operation, $mapped->draft, 'integration:query-builder', $source);
        }
    }

    /**
     * Enrich each exact filter with the subject model's cast for its column. The model's reflected
     * shape ({@see TypeEngine::classMetadata()} dependency files) and any enum-cast file join the
     * fragment-cache key (design §10), so editing the model or the enum invalidates the warm fragment.
     */
    private function enrichFilters(QueryBuilderFacts $facts, RouteContext $context): void
    {
        if ($facts->subjectModel === null) {
            return;
        }

        $model = $facts->subjectModel;
        $context->recordDependencyFiles(
            $context->engine->classMetadata(new ClassRef($model))->dependencyFiles,
        );

        $facts->filters = array_map(
            fn (QbEntry $filter): QbEntry => $this->enrichFilter($filter, $model, $context),
            $facts->filters,
        );
    }

    /**
     * Type one filter off the subject model per its kind: a resolved column (`exact`/static
     * `operator`/`callback`) off the model cast; a `scope` off its scope-method value parameter; a
     * `custom` off its class attribute or `__invoke` body. A partial/bare-string filter over an enum
     * column is never enum-typed — it earns an info nudge to switch to `exact` instead.
     */
    private function enrichFilter(QbEntry $filter, string $model, RouteContext $context): QbEntry
    {
        // A project factory (a ListFilters-style helper returning an AllowedFilter): record its file
        // (fragment-cache soundness) and, when it received a backed-enum class-string argument, type the
        // value off that enum directly — a single-value comparison, so a scalar enum (not the whereIn
        // array `exact` uses).
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
            // A project-factory filter with no enum argument types off its own name as the column (the
            // `$column ?? $key` idiom) — e.g. a boolean/uuid factory over the model's cast; a name that
            // is not a column (a multi-column search) degrades cleanly to a plain string.
            default => $filter->typeColumn !== null
                ? $this->applyColumn($filter, $this->columns->resolve($model, $filter->typeColumn), $context, asArray: false)
                : $filter,
        };
    }

    /** A {@see FilterColumn} for a backed-enum class-string recovered from a project-factory argument. */
    private function enumColumn(string $enumClass): FilterColumn
    {
        $file = EnumReflection::file($enumClass);

        return FilterColumn::enum($enumClass, $file !== null ? [$file] : []);
    }

    /** Record the project factory's declaring file as a fragment-cache dependency (its identity shapes typing). */
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
     * Apply a resolved {@see FilterColumn} onto a filter: an enum yields the backing values +
     * `x-enumDescriptions` (as a `whereIn` array only for the whereIn kinds — `$asArray`), a native
     * scalar its type, and none leaves the filter a plain string. The enum's declaring file joins the
     * fragment-cache dependency set.
     */
    private function applyColumn(QbEntry $filter, FilterColumn $column, RouteContext $context, bool $asArray): QbEntry
    {
        if ($column->isEnum() && $column->enum !== null) {
            $context->recordDependencyFiles($column->dependencyFiles);
            $schema = $context->converter()->convert(new EnumT($column->enum, EnumReflection::names($column->enum)));

            return $filter->withColumn($schema, enumTyped: $asArray);
        }

        if ($column->isScalar() && $column->scalarSchema !== null) {
            return $filter->withColumn($column->scalarSchema, enumTyped: false);
        }

        return $filter;
    }

    /**
     * Enrich a custom filter: a class-level `#[QueryParameter]` attribute (the explicit override) wins,
     * otherwise the column its `__invoke` body filters on types the value off the model cast. The
     * filter class file always joins the dependency set.
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

        return $facts->column !== null
            ? $this->applyColumn($filter, $this->columns->resolve($model, $facts->column), $context, asArray: false)
            : $filter;
    }

    /**
     * Fold a custom filter class's `#[QueryParameter]` into the filter's schema/description/default/
     * example (its `name` is ignored — the parameter name is the `AllowedFilter` name). Applied at the
     * integration layer, so a route-level attribute still overrides it downstream.
     */
    private function applyCustomAttribute(QbEntry $filter, QueryParameter $attribute, RouteContext $context): QbEntry
    {
        $default = is_scalar($attribute->default) ? $attribute->default : null;

        // Description / default / example are type-independent; set them first, then let the type
        // (if any) supply the schema — applyColumn preserves them.
        $filter = $filter->withColumn(
            null,
            enumTyped: false,
            comment: $attribute->description,
            hasDefault: $default !== null,
            default: $default,
            example: $attribute->example,
        );

        return $attribute->type === null
            ? $filter
            : $this->applyColumn($filter, $this->attributeColumn($attribute->type), $context, asArray: false);
    }

    /**
     * Interpret a custom-filter `#[QueryParameter(type: …)]` string as a {@see FilterColumn}: a backed
     * enum class-string yields the enum (backing values + `x-enumDescriptions` through the converter),
     * a scalar name (`int`/`string`/`bool`/`float`) yields its schema directly via the cast table, and
     * anything else leaves the value untyped. A scoped subset of the attribute-layer type grammar — a
     * custom filter's value is a scalar or an enum.
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

    /**
     * Info nudge: a partial (or bare-string) filter over an enum-cast column cannot document its
     * values (a partial match is a substring, not an enum member) — suggest `AllowedFilter::exact` for
     * exact matching with documented values. The filter is never enum-typed.
     */
    private function nudgePartialOnEnum(QbEntry $filter, string $model, RouteContext $context): void
    {
        $column = $this->columns->resolve($model, $filter->column());
        if (! $column->isEnum()) {
            return;
        }

        $context->recordDependencyFiles($column->dependencyFiles);
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
     * Silence kill: a paginating QB terminal was reached but NO allow-list entry was recovered (and
     * none even attempted-but-unresolved) — typically the `allowedFilters()`/`allowedSorts()` chain
     * lives behind an indirection the trace could not follow. Emit an info naming the action so the
     * loss is never silent. Skipped whenever any entry (recovered OR unresolved) was seen.
     */
    private function reportNoAllowLists(QueryBuilderFacts $facts, RouteContext $context): void
    {
        if (! $facts->paginates
            || $facts->filters !== [] || $facts->sorts !== [] || $facts->includes !== [] || $facts->fields !== []
            || $facts->unresolved !== []
        ) {
            return;
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'query-builder.no-allowlists-recovered',
            message: sprintf('A paginating Query Builder terminal was reached in %s but no allowed filters/sorts/includes were recovered.', $context->actionRef->symbol()),
            routeSignature: $context->route->signature(),
            help: 'If this endpoint offers filters/sorts, declare them via allowedFilters()/allowedSorts() where the trace can reach them (a method returning your QueryBuilder subclass is followed); otherwise this is expected.',
        ));
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
}
