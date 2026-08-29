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
use Docuccino\Core\Extensions\Validation\DeclaredBodyFields;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Core\Patch\Contribution;

/**
 * Applies the `#[BodyParameter]` declarations in the route's attribute bag to the request body (design
 * §Attribute set). Each one patches a single property of the recovered body — adding or overriding just
 * that property, keeping every recovered sibling — and creates a body outright when nothing was
 * recovered. The merge is re-applied at the attribute layer, so the attribute wins for the property it
 * names while a recovered body's media type (multipart, say) survives.
 *
 * The write itself is {@see DeclaredBodyFields}, shared with the TYPE-level declaration site: the same
 * field-path grammar, the same shallowest-first ordering, the same refusals.
 *
 * This site is the OPERATION's — a declaration written on the action, or on the controller class that
 * owns it. That is why it dereferences: it patches `schema.properties`, absent on a `$ref`, and one
 * operation's deviation is not the shared component's to carry. A declaration written on the request
 * TYPE is the other half of the same attribute and keeps the `$ref`; see
 * {@see RecoveredRequest::withDeclarations()}. Both are layer 40, and the operation's is the more
 * specific target, so it lands second and wins the properties it names.
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
        private readonly DeclaredBodyFields $fields = new DeclaredBodyFields,
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

        [$mediaType, $schema, $bodyRequired] = $this->existingBody($operation);

        [$schema, $declaredRequired, $diagnostics] = $this->fields->apply(
            $schema,
            $bodyParameters,
            $context->converter(),
            null,
            $context->actionSource(),
            $context->route->signature(),
        );

        foreach ($diagnostics as $diagnostic) {
            $context->components->addDiagnostic($diagnostic);
        }

        $operation->set(
            'requestBody',
            $this->assembleBody($mediaType, $schema, $bodyRequired || $declaredRequired),
            Contribution::attribute($context->actionSource()),
        );
    }

    /**
     * The recovered body as `[mediaType, schema, bodyRequired]`, or an empty `application/json` object
     * body. The first content media type wins, and the schema is rebuilt from the properties and the
     * `required` list alone: a body an operation's own `#[BodyParameter]` patches is inline by
     * construction ({@see RecoveredRequest}), so there is nothing else on it to keep.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: bool}
     */
    private function existingBody(OperationDraft $operation): array
    {
        $existing = $operation->resolvedField('requestBody');
        if (! is_array($existing)) {
            return ['application/json', self::objectSchema([], []), false];
        }

        $bodyRequired = ($existing['required'] ?? null) === true;

        $content = $existing['content'] ?? null;
        if (! is_array($content) || $content === []) {
            return ['application/json', self::objectSchema([], []), $bodyRequired];
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

        return [$mediaType, self::objectSchema($properties, $required), $bodyRequired];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function objectSchema(array $properties, array $required): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function assembleBody(string $mediaType, array $schema, bool $bodyRequired): array
    {
        $body = ['content' => [$mediaType => ['schema' => $schema]]];
        if ($bodyRequired || ($schema['required'] ?? []) !== []) {
            $body = ['required' => true] + $body;
        }

        return $body;
    }
}
