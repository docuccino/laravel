<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\TypeGrammar\TypeStringParser;

/**
 * Applies `#[BodyParameter]` attributes to the request body (design §Attribute set). Each one patches a
 * single property of the recovered body — adding or overriding just that property, keeping every
 * recovered sibling — and creates a body outright when nothing was recovered. The merge is re-applied at
 * the attribute layer, so the attribute wins for the property it names while a recovered body's media
 * type (multipart, say) survives.
 *
 * `requestBody` is ONE guarded field every producer writes whole, so a merge can only keep what it can
 * already read: this runs LATE in the Request phase, behind every recoverer that writes a body at the
 * integration layer (FormRequest/inline rules, spatie-Data, laravel-actions, and a third-party
 * recoverer at the default priority). Running ahead of them instead is not a lost merge but a lost
 * body — the attribute's one-property body wins the field at layer 40 and the recovered one is
 * shadowed, taking its 422 with it.
 */
#[ExtensionOrder(priority: Priorities::LATE)]
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

            // After the type keywords, so an explicit format wins over one the type string implied.
            if ($attribute->format !== null) {
                $property['format'] = $attribute->format;
            }
            if ($attribute->description !== null) {
                $property['description'] = $attribute->description;
            }
            if ($attribute->example !== null) {
                $property['example'] = $attribute->example;
            }

            // Just this one property; recovered siblings stay put.
            $properties[$attribute->name] = $property;
            $required = $this->withRequired($required, $attribute->name, $attribute->required);
        }

        $operation->set('requestBody', $this->assembleBody($mediaType, $properties, $required, $bodyRequired), Contribution::attribute($context->actionSource()));
    }

    /**
     * The recovered body as `[mediaType, properties, required, bodyRequired]`, or an empty
     * `application/json` object body. The first content media type wins.
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
     * Adds or drops a name in the `required` list, order-stable and duplicate-free.
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
