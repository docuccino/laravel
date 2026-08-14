<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\FrameworkClasses;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;

/**
 * Builds an error {@see ResponseDraft} from a handler/closure analysis (design §6): reads the recovered
 * `JsonResponse<TPayload, TStatus, TContentType, TMembers>` for the real status, payload shape and content
 * type (default `application/json`, `application/problem+json` when the helper set that header), then hoists
 * the payload schema through the route's converter.
 *
 * The example carries only members that folded to a literal — including a {@see StatusMarkerT} member (a
 * value echoing the response status) resolved to this response's status, so the 403 arm says `403`.
 * Required members that didn't fold are filled with type-derived placeholders (and the real status) so the
 * example is a valid instance of the schema beside it — see {@see example()} for why that fill is confined
 * to examples and nothing else. A status that didn't fold falls back to the one the body itself states, and
 * only then to the exception's own status hint ({@see foldStatus()}); a payload that didn't fold
 * ({@see UnknownT}) drops the body schema but keeps the status and media type.
 *
 * When the body is an object the engine watched being constructed, the fourth type arg names the arguments
 * it was built with, and those decide the example's membership rather than the schema's `required` list: an
 * argument passed at this call site is in THIS response even where the schema calls it optional, and one
 * that wasn't passed is absent even where the schema calls it required. Only the schema is ever consulted
 * for what such a member should look like.
 *
 * Null means no `JsonResponse` was recovered: either a `return null`/void arm ({@see isDelegation()} —
 * the renderer handing the type back to the framework, not a fold failure) or a body too dynamic to
 * fold. Reason phrases come from {@see FrameworkExceptionTable} so this tier can't drift from the others.
 */
final class HandlerResponseBuilder
{
    /** How far a placeholder follows nested schemas before flattening — a self-referential one never ends. */
    private const PLACEHOLDER_DEPTH = 4;

    public static function build(
        ActionAnalysis $analysis,
        RouteContext $context,
        Contribution $contribution,
        ?int $statusHint = null,
    ): ?ResponseDraft {
        foreach ($analysis->returns as $return) {
            $type = $return->type;
            if (! $type instanceof ClassT || $type->fqcn !== FrameworkClasses::JSON_RESPONSE) {
                continue;
            }

            $payload = $type->typeArgs[0] ?? null;
            $members = self::suppliedMembers($type->typeArgs[3] ?? null);

            $status = self::foldStatus($type->typeArgs[1] ?? null, $payload, $members, $statusHint);
            $draft = new ResponseDraft($status);
            $draft->setDescription(FrameworkExceptionTable::reason($status), $contribution);

            if ($payload !== null && ! $payload instanceof VoidT && ! $payload instanceof NeverT && ! $payload instanceof UnknownT) {
                $mediaType = self::contentType($type->typeArgs[2] ?? null);
                $payload = self::resolveStatusMarkers($payload, (int) $status);
                $schema = $context->converter()->toSchema($payload)->schema;
                foreach ($schema as $keyword => $value) {
                    $draft->content($mediaType)->set($keyword, $value, $contribution);
                }
                $example = self::example($payload, $schema, (int) $status, $context, $members);
                if ($example !== [] && self::satisfies($example, self::resolveSchema($schema, $context))) {
                    $draft->setExample($mediaType, $example);
                }
            }

            return $draft;
        }

        return null;
    }

    /**
     * Every recovered return is a `return null`/void arm, i.e. the renderer delegates to the framework.
     * The tier defers silently on these rather than raising a too-dynamic deferral.
     */
    public static function isDelegation(ActionAnalysis $analysis): bool
    {
        if ($analysis->returns === []) {
            return false;
        }

        foreach ($analysis->returns as $return) {
            if (! $return->type instanceof VoidT && ! $return->type instanceof NullT) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, DType>  $members
     */
    private static function foldStatus(mixed $statusArg, ?DType $payload, array $members, ?int $statusHint): string
    {
        if ($statusArg instanceof LiteralT && is_int($statusArg->value)) {
            return (string) $statusArg->value;
        }

        // Didn't fold (e.g. an enum method result, or a `$this->status` read a Data object's own
        // `toResponse()` makes). The body's own `status` may still have folded on the very same path, and
        // that beats the hint: the hint classifies the exception TYPE without reading the renderer at all,
        // so trusting it over folded evidence files the response under one status while the body beside it
        // states another — and names the shared component for the wrong one.
        $stated = self::statedStatus($payload, $members);
        if ($stated !== null) {
            return (string) $stated;
        }

        // Nothing folded either side — prefer the exception's own classification to 200.
        return (string) ($statusHint ?? 200);
    }

    /**
     * The status the body itself states, when a `status` member folded to a real HTTP status. A
     * {@see StatusMarkerT} member is deliberately not one: it means "echoes the response status", so
     * reading the status back out of it would be circular.
     *
     * @param  array<string, DType>  $members
     */
    private static function statedStatus(?DType $payload, array $members): ?int
    {
        $member = $members['status'] ?? self::shapeField($payload, 'status');

        if (! $member instanceof LiteralT || ! is_int($member->value) || $member->value < 100 || $member->value > 599) {
            return null;
        }

        return $member->value;
    }

    /** One field of an array-shape payload, by key. */
    private static function shapeField(?DType $payload, string $key): ?DType
    {
        if (! $payload instanceof ArrayShapeT || $payload->isList) {
            return null;
        }

        foreach ($payload->fields as $field) {
            if ((string) $field->key === $key) {
                return $field->type;
            }
        }

        return null;
    }

    private static function contentType(mixed $contentTypeArg): string
    {
        return $contentTypeArg instanceof LiteralT && is_string($contentTypeArg->value)
            ? $contentTypeArg->value
            : 'application/json';
    }

    /**
     * Pin each top-level status-echo member to the status this response is documented under, so it
     * converts to a `const` integer and lands in the example. Non-shape payloads pass through.
     */
    private static function resolveStatusMarkers(DType $payload, int $status): DType
    {
        if (! $payload instanceof ArrayShapeT) {
            return $payload;
        }

        return $payload->mapFieldTypes(
            static fn (DType $type): DType => $type instanceof StatusMarkerT ? new LiteralT($status) : $type,
        );
    }

    /**
     * A complete example for the documented body, or `[]` when the schema gives nothing to build one from.
     *
     * Members that folded to a literal are used verbatim; every other member the response is known to carry
     * is filled from the schema so the result is a valid instance rather than a partial one that fails
     * validation against the very schema it sits beside. That fill is the one place this tier writes a value
     * the code didn't state, and it is confined to examples — an example is illustrative by definition,
     * whereas a schema is a claim. Filled values are obvious placeholders derived from the declared type,
     * never a sentence invented on the app's behalf.
     *
     * "Known to carry" is `$members` first and the schema's `required` list second. A member the engine
     * watched being passed to the payload's constructor is in this response whatever its optionality says,
     * because being supplied at this call site is the stronger fact; a member the schema requires is in it
     * too, because the schema says so. Everything else is left out — the example shows the body this branch
     * produces, not every key that might appear.
     *
     * The single exception is `status`: a required, unconstrained integer member of that name is the status
     * this response is documented under (RFC 9457's own convention, and most of what makes a rendered
     * example worth reading), so it gets the real number.
     *
     * @param  array<string, mixed>  $schema  the converted body schema, possibly a bare `$ref`
     * @param  array<string, DType>  $members  supplied constructor argument → its folded literal or {@see UnknownT}
     * @return array<string, mixed>
     */
    private static function example(DType $payload, array $schema, int $status, RouteContext $context, array $members): array
    {
        $folded = self::assembleExample($payload);
        $resolved = self::resolveSchema($schema, $context);

        $properties = $resolved['properties'] ?? null;
        if (! is_array($properties) || $properties === []) {
            return $folded;
        }

        $required = is_array($resolved['required'] ?? null) ? $resolved['required'] : [];

        $example = [];
        foreach ($properties as $name => $spec) {
            $name = (string) $name;
            $spec = is_array($spec) ? $spec : [];

            if (array_key_exists($name, $folded)) {
                $example[$name] = $folded[$name];

                continue;
            }

            $supplied = $members[$name] ?? null;
            if ($supplied instanceof LiteralT) {
                $example[$name] = $supplied->value;

                continue;
            }

            $isRequired = in_array($name, $required, true);
            if ($supplied === null && ! $isRequired) {
                continue;
            }

            // A member the schema declares no type for has no truthful illustration — showing `"string"` for
            // what may well be a list would state something the code never said. A REQUIRED one is filled
            // anyway, since the alternative is dropping an example that would otherwise be complete.
            if ($isRequired || self::illustratable($spec, $context)) {
                $example[$name] = self::placeholder($name, $spec, $status, $context);
            }
        }

        return $example;
    }

    /**
     * Whether a member's schema says enough to build a placeholder from. A description and nothing else does
     * not: that's what an unresolved property type looks like once it reaches the document.
     *
     * @param  array<array-key, mixed>  $spec
     */
    private static function illustratable(array $spec, RouteContext $context): bool
    {
        $effective = self::effectiveSpec($spec, $context);

        return array_key_exists('const', $effective) || isset($effective['type']);
    }

    /**
     * A stand-in for one member: the `const` the schema pins, the real status for an integer `status`, else
     * a value that reads unmistakably as a placeholder for its declared type.
     *
     * @param  array<array-key, mixed>  $spec
     */
    private static function placeholder(string $name, array $spec, int $status, RouteContext $context): mixed
    {
        if (array_key_exists('const', $spec)) {
            return $spec['const'];
        }

        if ($name === 'status' && self::isType($spec['type'] ?? null, 'integer')) {
            return $status;
        }

        return self::typePlaceholder($spec, $context, 0);
    }

    /**
     * The declared type's placeholder, following a `$ref` and descending into composites: an array with an
     * `items` schema gets exactly ONE element built the same way, so a list of objects renders as a list of
     * something rather than an empty pair of brackets, and an object gets its own required members. Nothing
     * is invented for a member the schema doesn't require. The depth cap is what keeps a self-referential
     * schema from unrolling forever.
     *
     * @param  array<array-key, mixed>  $spec
     */
    private static function typePlaceholder(array $spec, RouteContext $context, int $depth): mixed
    {
        $spec = self::effectiveSpec($spec, $context);

        if (array_key_exists('const', $spec)) {
            return $spec['const'];
        }

        $type = $spec['type'] ?? null;
        $deeper = $depth + 1;

        if (self::isType($type, 'array')) {
            $items = $spec['items'] ?? null;

            return is_array($items) && $deeper < self::PLACEHOLDER_DEPTH
                ? [self::typePlaceholder($items, $context, $deeper)]
                : [];
        }

        if (self::isType($type, 'object')) {
            return $deeper < self::PLACEHOLDER_DEPTH ? self::objectPlaceholder($spec, $context, $deeper) : [];
        }

        return match (true) {
            self::isType($type, 'integer'), self::isType($type, 'number') => 0,
            self::isType($type, 'boolean') => false,
            default => 'string',
        };
    }

    /**
     * A nested object's required members only. An object requiring nothing comes out empty rather than
     * inventing a key, which is still a truthful instance of it.
     *
     * @param  array<array-key, mixed>  $spec
     * @return array<string, mixed>
     */
    private static function objectPlaceholder(array $spec, RouteContext $context, int $depth): array
    {
        $properties = $spec['properties'] ?? null;
        if (! is_array($properties)) {
            return [];
        }

        $required = is_array($spec['required'] ?? null) ? $spec['required'] : [];

        $example = [];
        foreach ($properties as $name => $property) {
            $name = (string) $name;
            if (in_array($name, $required, true)) {
                $example[$name] = self::typePlaceholder(is_array($property) ? $property : [], $context, $depth);
            }
        }

        return $example;
    }

    /**
     * The constructor arguments the engine watched the payload object being built with: name → its folded
     * {@see LiteralT}, or the {@see UnknownT} meaning "supplied here, value not statically knowable". An
     * absent name means the argument wasn't passed at that call site.
     *
     * Keyed by CONSTRUCTOR ARGUMENT name. A Data class whose properties are remapped on the way out simply
     * matches nothing here, and the example falls back to the schema's required members.
     *
     * @return array<string, DType>
     */
    private static function suppliedMembers(mixed $membersArg): array
    {
        if (! $membersArg instanceof ArrayShapeT) {
            return [];
        }

        $members = [];
        foreach ($membersArg->fields as $field) {
            $members[(string) $field->key] = $field->type;
        }

        return $members;
    }

    /** Whether a schema's `type` includes a name — it may be a nullable `[…, "null"]` array. */
    private static function isType(mixed $type, string $name): bool
    {
        return is_array($type) ? in_array($name, $type, true) : $type === $name;
    }

    /**
     * The schema a placeholder is actually derived from: the reference followed, and a nullable branch
     * (`anyOf: [X, {type: null}]` — how a nullable `$ref` or composite is expressed) reduced to `X`, since
     * illustrating the null branch would show nothing. The first non-null branch wins for a wider union;
     * picking one member of a union is what an example is.
     *
     * @param  array<array-key, mixed>  $spec
     * @return array<array-key, mixed>
     */
    private static function effectiveSpec(array $spec, RouteContext $context): array
    {
        $spec = self::resolveSchema($spec, $context);
        $branches = $spec['anyOf'] ?? $spec['oneOf'] ?? null;

        if (! is_array($branches)) {
            return $spec;
        }

        foreach ($branches as $branch) {
            if (is_array($branch) && ! self::isType($branch['type'] ?? null, 'null')) {
                return self::resolveSchema($branch, $context);
            }
        }

        return $spec;
    }

    /**
     * The body schema with a `#/components/schemas/*` reference followed, so `properties` and `required` are
     * visible. A hoisted Data class arrives as a bare `$ref`, which alone says nothing to build from.
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, mixed>
     */
    private static function resolveSchema(array $schema, RouteContext $context): array
    {
        $ref = $schema['$ref'] ?? null;
        $prefix = '#/components/schemas/';

        if (! is_string($ref) || ! str_starts_with($ref, $prefix)) {
            return $schema;
        }

        $component = $context->components->schemas()[substr($ref, strlen($prefix))] ?? null;

        return is_array($component) ? $component : $schema;
    }

    /**
     * The invariant {@see example()} is built to satisfy, checked rather than assumed: an example still
     * missing a required member would fail validation against its own schema, so it's dropped instead.
     *
     * @param  array<string, mixed>  $example
     * @param  array<array-key, mixed>  $schema
     */
    private static function satisfies(array $example, array $schema): bool
    {
        $required = $schema['required'] ?? [];
        if (! is_array($required)) {
            return true;
        }

        foreach ($required as $member) {
            if (! is_string($member) || ! array_key_exists($member, $example)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Only top-level members that folded to a literal go in; widened scalars, dynamic bodies and nested
     * shapes are omitted rather than invented. Declaration order, so it's deterministic. Empty → no
     * example emitted.
     *
     * @return array<string, string|int|float|bool>
     */
    private static function assembleExample(DType $payload): array
    {
        if (! $payload instanceof ArrayShapeT || $payload->isList) {
            return [];
        }

        $example = [];
        foreach ($payload->fields as $field) {
            if ($field->type instanceof LiteralT) {
                $example[(string) $field->key] = $field->type->value;
            }
        }

        return $example;
    }
}
