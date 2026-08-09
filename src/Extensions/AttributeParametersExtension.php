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
 * Applies the parameter attributes at the attribute precedence layer (design §7): query, header,
 * cookie and explicit path parameters, plus `#[IgnoreParam]` removals. Type strings are parsed to a
 * DType and converted through the route's schema chain, so `#[QueryParameter(type: 'int')]` gives an
 * integer schema.
 *
 * A bracketed name (`#[QueryParameter('filter[status]')]`) patches the matching property of a
 * deepObject container parameter when one exists — type/description/example/default onto the property
 * schema, `required` onto the container's `required` list — so the deepObject and flat bracketed
 * representations behave identically. With no such container it patches a flat `filter[status]`
 * parameter instead. Both create the member if it's missing.
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

        // Accumulate per container and write the `required` list once — a second equal-layer write
        // would shadow rather than append.
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
     * For a bracketed `parent[child]` name where a deepObject query parameter `parent` exists: the
     * `child` property draft (created if absent) plus both names. Null means patch a flat parameter.
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

    /** Type/description/default/example onto a deepObject property's schema. */
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
     * Merges each container's required child names into its schema's `required` list, once per
     * container: an equal-layer rewrite would shadow, not merge.
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
