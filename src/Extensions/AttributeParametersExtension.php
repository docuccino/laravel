<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\IgnoreParam;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Draft\SchemaDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Inference\PhpStan\Types\TypeStringParser;

/**
 * Applies the parameter attributes as the attribute precedence layer (design §7): query, header,
 * cookie and explicit path parameters, plus `#[IgnoreParam]` removals. Type strings are parsed to
 * a DType and converted through the route's schema chain, so `#[QueryParameter(type: 'int')]`
 * yields an integer schema.
 *
 * A bracketed query name (`#[QueryParameter('filter[status]')]`) patches the matching property of a
 * deepObject container parameter (`filter`, style deepObject) when one is present — the deepObject
 * representation's equivalent of the flat `filter[status]` parameter it patches under the bracketed
 * representation. type/description/example/default land on the property schema and `required` on the
 * container's `required` list; both styles patch an existing member and create a missing one, so the
 * two representations behave identically. With no such container (the default bracketed style) the
 * attribute keeps patching a flat `filter[status]` parameter exactly as before.
 */
final class AttributeParametersExtension implements OperationExtension
{
    public function __construct(
        private readonly TypeStringParser $types = new TypeStringParser,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        foreach ($context->attributes->all(IgnoreParam::class) as $ignore) {
            $this->remove($operation, $ignore->name, $ignore->in);
        }

        // Deep-object property `required` merges accumulate per container so the list is written once
        // (a second equal-layer write would shadow rather than append).
        $deepRequired = [];
        foreach ($context->attributes->all(QueryParameter::class) as $attribute) {
            $property = $this->deepObjectProperty($operation, $attribute->name);
            if ($property === null) {
                $parameter = $operation->parameter('query', $attribute->name);
                $this->apply($parameter, $context, $attribute->type, $attribute->description, $attribute->required, $attribute->default, $attribute->example);

                continue;
            }

            [$parentName, $childName, $schema] = $property;
            $this->applyToProperty($schema, $context, $attribute->type, $attribute->description, $attribute->default, $attribute->example);
            if ($attribute->required) {
                $deepRequired[$parentName][] = $childName;
            }
        }
        $this->applyDeepRequired($operation, $context, $deepRequired);

        foreach ($context->attributes->all(HeaderParameter::class) as $attribute) {
            $parameter = $operation->parameter('header', $attribute->name);
            $this->apply($parameter, $context, $attribute->type, $attribute->description, $attribute->required, null, $attribute->example);
        }

        foreach ($context->attributes->all(CookieParameter::class) as $attribute) {
            $parameter = $operation->parameter('cookie', $attribute->name);
            $this->apply($parameter, $context, $attribute->type, $attribute->description, $attribute->required, null, $attribute->example);
        }

        foreach ($context->attributes->all(PathParameter::class) as $attribute) {
            $parameter = $operation->parameter('path', $attribute->name);
            $this->apply($parameter, $context, $attribute->type, $attribute->description, true, null, $attribute->example);
            if ($attribute->format !== null) {
                $parameter->schema()->set('format', $attribute->format, Contribution::attribute($context->actionSource()));
            }
        }
    }

    private function apply(
        ParameterDraft $parameter,
        RouteContext $context,
        ?string $type,
        ?string $description,
        bool $required,
        mixed $default,
        mixed $example,
    ): void {
        $contribution = Contribution::attribute($context->actionSource());

        $parameter->setRequired($required, $contribution);
        $parameter->setDescription($description, $contribution);

        if ($type !== null) {
            foreach ($context->converter()->toSchema($this->types->parse($type))->schema as $keyword => $value) {
                $parameter->schema()->set($keyword, $value, $contribution);
            }
        }

        if ($default !== null) {
            $parameter->schema()->set('default', $default, $contribution);
        }

        if ($example !== null) {
            $parameter->set('example', $example, $contribution);
        }
    }

    /**
     * When `$name` is bracketed (`parent[child]`) AND a deepObject query parameter named `parent`
     * already exists, return its `child` property draft (created if absent, mirroring the flat path's
     * create-on-miss) with the parent/child names; otherwise null (patch a flat parameter as before).
     *
     * @return array{0: string, 1: string, 2: SchemaDraft}|null
     */
    private function deepObjectProperty(OperationDraft $operation, string $name): ?array
    {
        if (preg_match('/^([^\[\]]+)\[([^\[\]]+)\]$/', $name, $matches) !== 1) {
            return null;
        }

        [, $parent, $child] = $matches;
        if (! $operation->hasParameter('query', $parent)) {
            return null;
        }

        $container = $operation->parameter('query', $parent);
        if ($container->resolvedField('style') !== 'deepObject') {
            return null;
        }

        return [$parent, $child, $container->schema()->property($child)];
    }

    /** Apply the attribute's type/description/default/example onto a deepObject property's schema. */
    private function applyToProperty(
        SchemaDraft $property,
        RouteContext $context,
        ?string $type,
        ?string $description,
        mixed $default,
        mixed $example,
    ): void {
        $contribution = Contribution::attribute($context->actionSource());

        if ($type !== null) {
            foreach ($context->converter()->toSchema($this->types->parse($type))->schema as $keyword => $value) {
                $property->set($keyword, $value, $contribution);
            }
        }
        if ($description !== null) {
            $property->set('description', $description, $contribution);
        }
        if ($default !== null) {
            $property->set('default', $default, $contribution);
        }
        if ($example !== null) {
            $property->set('example', $example, $contribution);
        }
    }

    /**
     * Merge each container's required child names into its object schema's `required` list — once per
     * container, since re-writing an equal-layer field would be shadowed rather than merged.
     *
     * @param  array<string, list<string>>  $deepRequired
     */
    private function applyDeepRequired(OperationDraft $operation, RouteContext $context, array $deepRequired): void
    {
        foreach ($deepRequired as $parent => $children) {
            $schema = $operation->parameter('query', $parent)->schema();
            $existing = $schema->resolvedField('required') ?? [];
            $existingNames = is_array($existing) ? array_values(array_filter($existing, 'is_string')) : [];
            $merged = array_values(array_unique([...$existingNames, ...$children]));

            $schema->set('required', $merged, Contribution::attribute($context->actionSource()));
        }
    }

    private function remove(OperationDraft $operation, string $name, ?string $in): void
    {
        foreach ($in === null ? ['query', 'path', 'header', 'cookie'] : [$in] as $location) {
            $operation->removeParameter($location, $name);
        }
    }
}
