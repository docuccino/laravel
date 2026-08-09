<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Inference\PhpStan\Types\TypeStringParser;

/**
 * Applies `#[BodyParameter]` attributes to the request body (design §Attribute set). Each attribute
 * PATCHES a single property of the inferred request body (from a FormRequest, inline validation, or a
 * spatie Data class): its named property is added or overridden while every inferred sibling property
 * is kept. When no request body was inferred, the attributes create one. The merged body is re-applied
 * at the attribute layer (PatchGuard-recorded), so an attribute always wins over inference for the
 * property it names, and the media type of an inferred body (e.g. multipart) is preserved.
 */
final class AttributeRequestBodyExtension implements OperationExtension
{
    public function __construct(
        private readonly TypeStringParser $types = new TypeStringParser,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Request;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $bodyParameters = $context->attributes->all(BodyParameter::class);
        if ($bodyParameters === []) {
            return;
        }

        [$mediaType, $properties, $required, $bodyRequired] = $this->existingBody($operation);

        foreach ($bodyParameters as $attribute) {
            $property = $attribute->type !== null
                ? $context->converter()->toSchema($this->types->parse($attribute->type))->schema
                : ['type' => 'string'];

            if ($attribute->description !== null) {
                $property['description'] = $attribute->description;
            }
            if ($attribute->example !== null) {
                $property['example'] = $attribute->example;
            }

            // Add or override just this one property; inferred siblings stay in $properties.
            $properties[$attribute->name] = $property;
            $required = $this->withRequired($required, $attribute->name, $attribute->required);
        }

        $operation->set('requestBody', $this->assembleBody($mediaType, $properties, $required, $bodyRequired), Contribution::attribute($context->actionSource()));
    }

    /**
     * The already-inferred body decomposed into `[mediaType, properties, required, bodyRequired]`, or an
     * empty `application/json` object body when nothing was inferred. The FIRST content media type wins,
     * so a merge preserves an inferred multipart/JSON body's media type.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: list<string>, 3: bool}
     */
    private function existingBody(OperationDraft $operation): array
    {
        $existing = $operation->resolvedField('requestBody');
        if (! is_array($existing)) {
            return ['application/json', [], [], false];
        }

        $bodyRequired = ($existing['required'] ?? null) === true;

        $content = $existing['content'] ?? null;
        if (! is_array($content) || $content === []) {
            return ['application/json', [], [], $bodyRequired];
        }

        $firstKey = array_key_first($content);
        $mediaType = is_string($firstKey) ? $firstKey : 'application/json';

        $entry = $content[$firstKey];
        $existingSchema = is_array($entry) && is_array($entry['schema'] ?? null) ? $entry['schema'] : [];

        $properties = [];
        foreach (is_array($existingSchema['properties'] ?? null) ? $existingSchema['properties'] : [] as $name => $schema) {
            if (is_string($name)) {
                $properties[$name] = $schema;
            }
        }

        $required = is_array($existingSchema['required'] ?? null)
            ? array_values(array_filter($existingSchema['required'], 'is_string'))
            : [];

        return [$mediaType, $properties, $required, $bodyRequired];
    }

    /**
     * Add or drop a property name in the schema's `required` list (order-stable, no duplicates).
     *
     * @param  list<string>  $required
     * @return list<string>
     */
    private function withRequired(array $required, string $name, bool $isRequired): array
    {
        $required = array_values(array_filter($required, static fn (string $entry): bool => $entry !== $name));
        if ($isRequired) {
            $required[] = $name;
        }

        return $required;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function assembleBody(string $mediaType, array $properties, array $required, bool $bodyRequired): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        $body = ['content' => [$mediaType => ['schema' => $schema]]];
        if ($bodyRequired || $required !== []) {
            $body = ['required' => true] + $body;
        }

        return $body;
    }
}
