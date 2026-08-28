<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Validation\FieldPath;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\TypeGrammar\TypeStringParser;

/**
 * Applies `#[BodyParameter]` attributes to the request body (design §Attribute set). Each one patches a
 * single property of the recovered body — adding or overriding just that property, keeping every
 * recovered sibling — and creates a body outright when nothing was recovered. The merge is re-applied at
 * the attribute layer, so the attribute wins for the property it names while a recovered body's media
 * type (multipart, say) survives.
 *
 * The name is a field PATH, split by {@see FieldPath}: `meta.validation_overrides` documents
 * `validation_overrides` inside `meta`, `items.*.id` documents `id` on an item, and `\.` names a field
 * whose own name holds a dot. That is the grammar the recovered body was assembled from, so the
 * attribute that patches one of its properties reads a name the way its producer wrote one.
 * Declarations are applied shallowest first, so a parent named by one attribute is in place before a
 * child named by another, whichever order the two were written in.
 *
 * A path only reaches somewhere the body can carry it. Where the parent it names is a scalar, a
 * composition, or a `$ref` to a shared component — which every other operation using that component
 * would inherit the new property from — nothing is written and the refusal is reported.
 *
 * `requestBody` is ONE guarded field every producer writes whole, so a merge can only keep what it can
 * already read: this runs LATE in the Request phase, behind every recoverer that writes a body at the
 * integration layer (FormRequest/inline rules, spatie-Data, laravel-actions, and a third-party
 * recoverer at the default priority). Running ahead of them instead is not a lost merge but a lost
 * body — the attribute's one-property body wins the field at layer 40 and the recovered one is
 * shadowed, taking its 422 with it.
 *
 * @phpstan-type BodyPathRefusal array{container: string, says: string, shared: bool}
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class AttributeRequestBodyExtension implements OperationExtension
{
    /** What a `$ref` parent is called in the refusal, and the answer that picks its remedy. */
    private const string SHARED = 'a reference to a shared component';

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

        [$mediaType, $schema, $bodyRequired] = $this->existingBody($operation);

        foreach ($this->shallowestFirst($bodyParameters) as $attribute) {
            // A field the server insists on is a body the server insists on, however deep the field
            // sits — and only the top level of `required` is on the root schema to say so.
            $documented = $this->apply($schema, $attribute, $context);
            $bodyRequired = $bodyRequired || ($documented && $attribute->required);
        }

        $operation->set('requestBody', $this->assembleBody($mediaType, $schema, $bodyRequired), Contribution::attribute($context->actionSource()));
    }

    /**
     * The declarations with every parent ahead of its children, source order otherwise. A path descends
     * into whatever its parent is by the time it runs, so leaving that to the order the attributes
     * happen to be written in would let a `#[BodyParameter('meta')]` replace the `meta` that a
     * `#[BodyParameter('meta.x')]` above it had just filled in.
     *
     * @param  list<BodyParameter>  $bodyParameters
     * @return list<BodyParameter>
     */
    private function shallowestFirst(array $bodyParameters): array
    {
        // Stable since PHP 8.0, which is what keeps two declarations at the same depth in the order
        // their author wrote them.
        usort(
            $bodyParameters,
            static fn (BodyParameter $a, BodyParameter $b): int => count(FieldPath::segments($a->name)) <=> count(FieldPath::segments($b->name)),
        );

        return $bodyParameters;
    }

    /**
     * Writes one declaration into the body schema, or reports why the body cannot carry it. Returns
     * whether it documented anything.
     *
     * @param  array<string, mixed>  $schema
     */
    private function apply(array &$schema, BodyParameter $attribute, RouteContext $context): bool
    {
        if (! FieldPath::isWellFormed($attribute->name)) {
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Warning,
                code: 'attribute.body-parameter-name',
                message: sprintf(
                    '#[BodyParameter(name: "%s")] names no body field — a field path has no empty segments — so no property was documented.',
                    $attribute->name,
                ),
                source: $context->actionSource(),
                routeSignature: $context->route->signature(),
                help: 'Write the name as a validation rule key is written: `nickname`, `meta.validation_overrides`, `items.*.id`. A dot that belongs to the field name itself is escaped `\\.`.',
            ));

            return false;
        }

        $refusal = $this->write($schema, FieldPath::segments($attribute->name), $this->property($attribute, $context), $attribute->required, []);
        if ($refusal === null) {
            return true;
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.body-parameter-parent',
            message: sprintf(
                '#[BodyParameter(name: "%s")] nests under %s, documented as %s, so no property was documented.',
                $attribute->name,
                $refusal['container'],
                $refusal['says'],
            ),
            source: $context->actionSource(),
            routeSignature: $context->route->signature(),
            help: $refusal['shared']
                ? 'Every operation using that component would inherit the property, so it is not one operation\'s to add. Declare it where the component is defined, or patch this body with an overlay.'
                : 'Document the parent as an object first — a #[BodyParameter] naming it with `type: \'object\'` — or name a top-level field instead. A dot that belongs to the field name itself is escaped `\\.`.',
        ));

        return false;
    }

    /**
     * The schema one declaration publishes for the field it names.
     *
     * @return array<string, mixed>
     */
    private function property(BodyParameter $attribute, RouteContext $context): array
    {
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

        return $property;
    }

    /**
     * Places `$property` at `$segments` under `$node`, creating the containers on the way. Returns null
     * on success, and otherwise what stopped it — leaving `$node` exactly as it found it, because a
     * declaration that documented nothing should not leave a half-built container behind either.
     *
     * @param  array<string, mixed>  $node  the container the first segment is a member of
     * @param  non-empty-list<string>  $segments
     * @param  array<string, mixed>  $property
     * @param  list<string>  $walked  the segments already descended through, for the refusal
     * @return BodyPathRefusal|null
     */
    private function write(array &$node, array $segments, array $property, bool $required, array $walked): ?array
    {
        $segment = $segments[0];
        $rest = array_slice($segments, 1);

        $says = self::cannotCarry($node, $segment === '*' ? 'array' : 'object');
        if ($says !== null) {
            return [
                'container' => $walked === [] ? 'the request body' : '`'.implode('.', $walked).'`',
                'says' => $says,
                'shared' => $says === self::SHARED,
            ];
        }

        if ($segment === '*') {
            /** @var array<string, mixed> $child */
            $child = is_array($node['items'] ?? null) ? $node['items'] : [];

            if ($rest === []) {
                // A `*` leaf describes the element itself, so there is no object to mark it required on.
                $child = $property;
            } else {
                $refusal = $this->write($child, $rest, $property, $required, [...$walked, $segment]);
                if ($refusal !== null) {
                    return $refusal;
                }
            }

            $node['type'] = 'array';
            $node['items'] = $child;

            return null;
        }

        /** @var array<string, mixed> $properties */
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];

        if ($rest !== []) {
            /** @var array<string, mixed> $child */
            $child = is_array($properties[$segment] ?? null) ? $properties[$segment] : [];

            $refusal = $this->write($child, $rest, $property, $required, [...$walked, $segment]);
            if ($refusal !== null) {
                return $refusal;
            }

            $properties[$segment] = $child;
            $node['type'] = 'object';
            $node['properties'] = $properties;

            return null;
        }

        $properties[$segment] = $property;
        $node['type'] = 'object';
        $node['properties'] = $properties;

        $existing = is_array($node['required'] ?? null)
            ? array_values(array_filter($node['required'], 'is_string'))
            : [];
        $names = $this->withRequired($existing, $segment, $required);

        if ($names === []) {
            unset($node['required']);
        } else {
            $node['required'] = $names;
        }

        return null;
    }

    /**
     * What stops this schema holding a nested member of `$kind`, in the words the refusal quotes — null
     * when nothing does. A schema with no `type` is taken as the container the path says it is: it
     * claims nothing the declaration contradicts.
     *
     * @param  array<string, mixed>  $node
     */
    private static function cannotCarry(array $node, string $kind): ?string
    {
        if (isset($node['$ref'])) {
            return self::SHARED;
        }

        // Drawn from the keyword table rather than listed again here: a schema whose shape is given by
        // a LIST of subschemas has no one branch a nested field belongs in.
        foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST) as $keyword) {
            if (isset($node[$keyword])) {
                return '`'.$keyword.'`';
            }
        }

        $declared = $node['type'] ?? null;
        if ($declared === null) {
            return null;
        }

        $types = array_values(array_filter(is_array($declared) ? $declared : [$declared], 'is_string'));
        if (in_array($kind, $types, true)) {
            return null;
        }

        return $types === [] ? 'something other than an '.$kind : '`'.implode('`/`', $types).'`';
    }

    /**
     * The recovered body as `[mediaType, schema, bodyRequired]`, or an empty `application/json` object
     * body. The first content media type wins, and the schema is rebuilt from the properties and the
     * `required` list alone: a body a `#[BodyParameter]` patches is inline by construction
     * ({@see RecoveredRequest}), so there is nothing else on it to
     * keep.
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
