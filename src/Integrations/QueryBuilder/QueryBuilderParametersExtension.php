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
 * Documents a `spatie/laravel-query-builder` list endpoint. Traces the action with a
 * {@see QueryBuilderTraceVisitor} (via {@see RouteContext::trace()}, so the walk's files join the
 * fragment-cache key) to recover the subject model, allow-lists and pagination, then enriches each
 * filter with its column's cast — an enum's backing values through the shared enum machinery, so
 * `#[CaseDescription]` prose lands as `x-enumDescriptions`, or a native cast type. The facts become
 * query parameters under the document's representation policy and the package's own parameter names.
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
     * In strict mode (the package default) an unknown filter/sort/include raises `InvalidQuery` → 400.
     * A synthetic 400 goes through the resolved exception→response chain so its body matches the
     * document's error style. Skipped when strict mode is off or `error_responses => 'none'`.
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
     * Enriches each filter with the subject model's cast for its column. The model's reflected shape and
     * any enum-cast file join the fragment-cache key, so editing either invalidates the warm fragment.
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
     * Types one filter off the subject model per kind: `exact`/static `operator`/`callback` off the
     * resolved column's cast, a `scope` off its scope method's value parameter, a `custom` off its class
     * attribute or `__invoke` body. A partial or bare-string filter over an enum column is never
     * enum-typed — a substring match isn't an enum member — and gets a nudge towards `exact` instead.
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
            // A project-factory filter with no enum argument types off its own name as the column (the
            // `$column ?? $key` idiom). A name that isn't a column — a multi-column search — stays a string.
            default => $filter->typeColumn !== null
                ? $this->applyColumn($filter, $this->columns->resolve($model, $filter->typeColumn), $context, asArray: false)
                : $filter,
        };
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
     * A class-level `#[QueryParameter]` on the filter class is the explicit override and wins; otherwise
     * the column its `__invoke` body filters on types the value.
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
     * Folds the attribute's schema/description/default/example into the filter. Its `name` is ignored —
     * the parameter name is always the `AllowedFilter` name. A route-level attribute still overrides
     * this downstream.
     */
    private function applyCustomAttribute(QbEntry $filter, QueryParameter $attribute, RouteContext $context): QbEntry
    {
        $default = is_scalar($attribute->default) ? $attribute->default : null;

        // Description/default/example are type-independent, so set them first and let the type supply
        // the schema afterwards — applyColumn preserves them.
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
     * A paginating terminal was reached but not one allow-list entry turned up, recovered or unresolved
     * — usually the chain lives behind an indirection the trace couldn't follow. Names the action so the
     * loss isn't silent.
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
