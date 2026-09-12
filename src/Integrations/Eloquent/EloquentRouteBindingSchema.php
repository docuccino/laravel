<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\RouteBindingFieldSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingKeyResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Inference\ClassRef;

/**
 * The gated route-binding resolvers contributed by the Eloquent integration: it answers all three
 * binding questions, typing a path parameter from the bound model's route key (uuid/ulid/string/integer),
 * typing a `{post:slug}` parameter from THAT column, and naming the column an implicit binding is
 * matched on. Contributed only when `eloquent` is enabled, so a disabled integration leaves the path
 * parameter to the built-in string fallback rather than typing it off the model.
 */
final class EloquentRouteBindingSchema implements RouteBindingFieldSchemaResolver, RouteBindingKeyResolver, RouteBindingSchemaResolver
{
    public function __construct(
        private readonly EloquentModelReflector $reflector = new EloquentModelReflector,
    ) {}

    /**
     * The bound model's route-key schema, or null when the column the segment is matched against is
     * not the one this can see. Two ways that happens, and the answer is the same for both: a binding
     * that is no Eloquent model at all — a custom `UrlRoutable` keys on whatever its
     * `resolveRouteBinding` says — and a model that decides its route key in a method body.
     *
     * The second case used to answer with the PRIMARY key's shape as the closest static answer, and
     * closest is not the bar: a model binding on a slug published `integer`, which refuses every value
     * the route actually accepts and gives a generated client a parameter it cannot pass. A vague true
     * shape beats a precise false one, so this defers and the caller documents a plain string and says
     * so.
     *
     * @return array<string, mixed>|null
     */
    public function keySchemaFor(string $modelFqcn): ?array
    {
        return $this->keyNameFor($modelFqcn) === null
            ? null
            : $this->reflector->keySchemaFor($modelFqcn);
    }

    /**
     * The column an implicit binding matches on, or null for a non-Eloquent binding and for a model
     * that decides its route key in a method body ({@see EloquentModelReflector::routeKeyNameFor()}).
     */
    public function keyNameFor(string $modelFqcn): ?string
    {
        return $this->reflector->routeKeyNameFor($modelFqcn);
    }

    /**
     * Unlike {@see keySchemaFor()} this one DOES answer null — for a non-Eloquent binding, or a column
     * nothing types — and the caller then documents a plain string
     * ({@see RouteBindingFieldSchemaResolver}).
     *
     * @return array<string, mixed>|null
     */
    public function fieldSchemaFor(RouteContext $context, string $modelFqcn, string $field): ?array
    {
        if (! EloquentModelReflector::isModel($modelFqcn)) { // a pure predicate on a class name
            return null;
        }

        // The `@property` tags this reads live in the model file (and its parents'), so retyping a
        // column has to invalidate the warm fragment.
        $metadata = $context->engine->classMetadata(new ClassRef($modelFqcn));
        $context->recordDependencyFiles($metadata->dependencyFiles);

        [$schema, $formatGivenUp] = $this->reflector->columnSchemaFor($modelFqcn, $field, $metadata);

        if ($formatGivenUp) {
            $this->reportWeakenedDate($context, $modelFqcn, $field);
        }

        return $schema;
    }

    /**
     * A path segment is the other place a model's date attribute reaches the document, so it is the
     * other place the `serializeDate()` override can take a `format` away ({@see DateColumnSchema}).
     * Named per parameter rather than per model: a reader correcting this one states the parameter, not
     * the component.
     *
     * What it reports is the shape the column read came back with. The parameter itself is written a
     * whole extension later and `#[PathParameter]` carries a `format:`, so a claim about the published
     * parameter would be false for the reader who declared one
     * (docs/design/defect-classes.md §"A diagnostic that asserts an outcome it never reads").
     */
    private function reportWeakenedDate(RouteContext $context, string $modelFqcn, string $field): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'eloquent.custom-date-serialization',
            message: sprintf(
                'The parameter binds on %s::$%s, a date attribute of a model that overrides serializeDate(), so its wire format is not statically known and the shape recovered for the segment is a string with no format.',
                $modelFqcn,
                $field,
            ),
            routeSignature: $context->route->signature($context->httpMethod()),
            help: 'Nothing recovers the format: no attribute carries a column format, and a docblock type has no format to state. If clients need an exact one, name the segment in a #[PathParameter] on the action and give it a `format:`, or state the parameter in an overlay — either publishes the format, and this notice keeps naming the column that could not state it.',
        ));
    }
}
