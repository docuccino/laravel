<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Links;

use Docuccino\Attributes\ResponseLink;
use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Document\DocumentGraph;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Links\BindingTake;
use Docuccino\Core\Links\BodyGive;
use Docuccino\Core\Links\DeclaredLink;
use Docuccino\Core\Links\OperationLinks;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Routing\BoundColumn;
use Docuccino\Laravel\Routing\OasPath;
use Illuminate\Contracts\Routing\UrlRoutable;

/**
 * Records what this operation's code proves about the links it takes part in — the columns its path
 * parameters are matched on, the columns its success responses send back and where, and the links it
 * declares with `#[ResponseLink]` — for {@see ResponseLinkAssembly} to join across the document. It
 * publishes nothing itself.
 *
 * Finalize, because both halves are read off the operation as published: a response's status and body
 * are settled by then, and a pointer into a body is only worth recording if the body documents it.
 *
 * @internal
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class ResponseLinkFacts implements OperationExtension
{
    public const string CHANNEL = 'response-link';

    public function __construct(
        private readonly BodyColumns $columns = new BodyColumns,
        private readonly DataColumns $data = new DataColumns,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $facts = new OperationLinks(
            takes: $this->takes($context),
            gives: $this->gives($operation, $context),
            declared: self::declared($context),
        );

        if (! $facts->isEmpty()) {
            $context->notes()->record(self::CHANNEL, self::signature($context), $facts->toJson());
        }
    }

    /** The operation as the document names it — a function of the route alone, so it survives a shared fragment. */
    public static function signature(RouteContext $context): string
    {
        return strtoupper($context->documentedMethod ?? $context->route->primaryMethod()).' '.OasPath::of($context->route->uri);
    }

    /**
     * The links the action declares with `#[ResponseLink]`, each carrying where it was written.
     *
     * @return list<DeclaredLink>
     */
    private static function declared(RouteContext $context): array
    {
        $declared = [];

        foreach ($context->attributes->all(ResponseLink::class) as $link) {
            $declared[] = new DeclaredLink(
                operation: trim($link->operation),
                parameters: $link->parameters,
                name: trim($link->name),
                status: $link->status === null ? null : (string) $link->status,
                description: trim($link->description),
                source: $context->actionSource(),
            );
        }

        return $declared;
    }

    /**
     * @return array<string, BindingTake>
     */
    private function takes(RouteContext $context): array
    {
        $takes = [];

        foreach ($context->pathParameters as $name) {
            $bound = $context->routeBindings[$name] ?? null;
            $column = BoundColumn::of($context, $name);

            if ($bound !== null && $column !== null && is_subclass_of($bound, UrlRoutable::class)) {
                $takes[$name] = new BindingTake($bound, $column, $context->scopedBindings[$name] ?? null);
            }
        }

        return $takes;
    }

    /**
     * @return list<BodyGive>
     */
    private function gives(OperationDraft $operation, RouteContext $context): array
    {
        $sources = null;
        $bodies = $this->bodies($context, $sources);
        if ($bodies === []) {
            return [];
        }

        $components = $context->components->schemas();
        $classByName = [];
        foreach ($context->components->schemaIds() as $name => $publishedId) {
            if (isset($bodies[$publishedId])) {
                $classByName[$name] = $bodies[$publishedId];
            }
        }

        $gives = [];
        foreach ($operation->responseStatuses() as $status) {
            $response = $operation->response($status);
            $mediaType = $response->primaryMediaType();
            if (preg_match('/^2\d\d$/', $status) !== 1 || ! self::isJson($mediaType)) {
                continue;
            }

            foreach (self::placements($response->content($mediaType)->freeze()->toArray()) as [$name, $at]) {
                $body = $classByName[$name] ?? null;
                $properties = $components[$name]['properties'] ?? null;
                if ($body === null || ! is_array($properties)) {
                    continue;
                }

                [$class, $model] = $body;
                $published = array_map(strval(...), array_keys($properties));
                $carried = $model === null
                    ? $this->data->carried($class, $sources?->constructionsOf($class) ?? [], $published, $context)
                    : array_map(static fn (string $column): array => [$model, $column], $this->columns->carried($class, $model, $published, $context));

                foreach ($carried as $member => [$from, $column]) {
                    $gives[] = new BodyGive($status, $from, $column, Pointer::of([...$at, (string) $member]), $class);
                }
            }
        }

        return $gives;
    }

    /**
     * The model bodies this operation returns, by the identity their component is published under:
     * a model itself, a resource whose model is known — from where the action builds it, or from its
     * `@mixin` where the action builds it nowhere the walk sees — or a Data class the walk saw built, whose
     * model is read per property (null here, the constructions in `$sources`).
     *
     * @return array<string, array{0: string, 1: string|null}> published id → [class, model]
     */
    private function bodies(RouteContext $context, ?DataSourceVisitor &$sources): array
    {
        $classes = [];
        foreach ($context->analysis()->returns as $return) {
            self::classesIn($return->type, $classes);
        }

        $bodies = [];
        $visitor = null;
        foreach (array_keys($classes) as $class) {
            if (EloquentModelReflector::isModel($class)) {
                $bodies[SchemaIdentity::publishedId($class)] = [$class, $class];

                continue;
            }

            if (DataClassReflector::isData($class)) {
                if ($sources === null) {
                    $sources = new DataSourceVisitor;
                    $context->trace($sources);
                }

                if ($sources->constructionsOf($class) !== null) {
                    $bodies[SchemaIdentity::publishedId($class)] = [$class, null];
                }

                continue;
            }

            if (! ResourceReflector::isResource($class) || ResourceReflector::isCollection($class) || ResourceReflector::isJsonApiResource($class)) {
                continue;
            }

            if ($visitor === null) {
                $visitor = new ResourceModelVisitor;
                $context->trace($visitor);
            }

            $model = $visitor->wrappedModel($class);
            if ($model !== null) {
                $bodies[SchemaIdentity::publishedId($class)] = [$class, $model];
            }
        }

        return $bodies;
    }

    /**
     * @param  array<string, true>  $classes
     */
    private static function classesIn(DType $type, array &$classes, int $depth = 0): void
    {
        if ($depth > 8) {
            return;
        }

        if ($type instanceof UnionT) {
            foreach ($type->members as $member) {
                self::classesIn($member, $classes, $depth + 1);
            }

            return;
        }

        if ($type instanceof ClassT) {
            $classes[$type->fqcn] = true;
            foreach ($type->typeArgs as $argument) {
                self::classesIn($argument, $classes, $depth + 1);
            }
        }
    }

    /**
     * Each component the body holds at a fixed place: the body itself, or a member of an object the body
     * spells out inline (the `data` a resource is wrapped in). A component under a branch keyword or a
     * list could be any of several places, so it is not one.
     *
     * @param  array<array-key, mixed>  $schema
     * @param  list<string>  $at
     * @return list<array{0: string, 1: list<string>}> [component name, the members leading to it]
     */
    private static function placements(array $schema, array $at = []): array
    {
        $ref = DocumentGraph::componentRef($schema);
        if ($ref !== null) {
            $parts = DocumentGraph::componentParts($ref);

            return $parts !== null && $parts[0] === 'schemas' ? [[$parts[1], $at]] : [];
        }

        $properties = $schema['properties'] ?? null;
        if (! is_array($properties) || count($at) > 4) {
            return [];
        }

        $placements = [];
        foreach ($properties as $member => $property) {
            if (is_array($property)) {
                array_push($placements, ...self::placements($property, [...$at, (string) $member]));
            }
        }

        return $placements;
    }

    private static function isJson(string $mediaType): bool
    {
        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }
}
