<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use BackedEnum;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Contracts\RouteBindingFieldSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Patch\Contribution;
use ReflectionEnum;

/**
 * Adds a path parameter for every `{param}` in the route template (design §Route-model binding). A
 * model-bound parameter is typed from the model's route key (uuid/ulid/int, with format) via the gated
 * {@see RouteBindingSchemaResolver} chain, so `{model}` matches the real key instead of a hardcoded
 * integer; `{model:column}` is typed from THAT column instead, through the same chain. A
 * string-backed-enum hint is Laravel's implicit enum binding, so it types as that enum. An unbound
 * segment — a disabled Eloquent integration, an int-backed or pure enum, a custom `UrlRoutable` —
 * gives a required string, and `#[PathParameter]` can refine any of it from the higher attribute layer.
 *
 * A route with `->withTrashed()` flags each bound parameter: a note on the description plus an
 * `x-docuccino.facts.routeBinding.withTrashed` fact, so consumers know soft-deleted records resolve.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class PathParametersExtension implements OperationExtension
{
    private const TRASHED_NOTE = 'Resolves soft-deleted (trashed) records as well as active ones.';

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        foreach ($context->pathParameters as $name) {
            $parameter = $operation->parameter('path', $name);

            $isBound = isset($context->routeBindings[$name]);
            $contribution = $isBound
                ? Contribution::inference($context->actionSource())
                : Contribution::fallback();

            $parameter->setRequired(! in_array($name, $context->optionalPathParameters, true), $contribution);

            if ($isBound) {
                // Switching a model to HasUuids changes the route-key schema, so a warm fragment has to
                // invalidate (design §10).
                $this->recordModelFile($context, $context->routeBindings[$name]);
            }

            $field = $context->routeBindingFields[$name] ?? null;
            $keySchema = $isBound ? $this->boundSchema($context, $name, $field) : null;

            if ($keySchema !== null) {
                foreach ($keySchema as $keyword => $value) {
                    $parameter->schema()->set((string) $keyword, $value, $contribution);
                }
            } else {
                // A binding nothing could type is a fallback string, not an inferred one — and it is
                // reported, because the route says more about this parameter than the document does.
                $degraded = $isBound;
                $parameter->schema()->set('type', 'string', $degraded ? Contribution::fallback() : $contribution);

                if ($isBound && $field !== null) {
                    $this->reportUntypedColumn($context, $name, $context->routeBindings[$name], $field);
                } elseif ($isBound) {
                    $this->reportUntypedBinding($context, $name, $context->routeBindings[$name]);
                }
            }

            if ($isBound && $context->allowsTrashedBindings) {
                $parameter->setDescription(self::TRASHED_NOTE, $contribution);
                $parameter->setDocuccinoFact('routeBinding', ['withTrashed' => true]);
            }
        }
    }

    /**
     * The schema a bound parameter resolves to: the named column's when the route names one
     * (`{post:slug}`), else the model's route key. The two are NOT interchangeable, so an untyped column
     * falls through to the caller's string fallback rather than back to the key
     * ({@see RouteBindingFieldSchemaResolver}).
     *
     * @return array<string, mixed>|null
     */
    private function boundSchema(RouteContext $context, string $name, ?string $field): ?array
    {
        $modelFqcn = $context->routeBindings[$name];

        if ($field !== null) {
            return $context->routeBindingFieldSchema($modelFqcn, $field);
        }

        // Laravel's implicit binding resolves a STRING-backed enum from the segment (`tryFrom`,
        // 404 on a miss), so the value domain is the enum's backing values exactly. Int-backed and
        // pure enums are never implicitly bound (Laravel's Reflector requires a string backing
        // type), so they fall through to the string fallback like any other untypable hint.
        if (self::isStringBackedEnum($modelFqcn)) {
            return $context->converter()->convert(new EnumT($modelFqcn, EnumReflection::names($modelFqcn)));
        }

        return $context->routeBindingKeySchema($modelFqcn);
    }

    /** Only a string-backed enum is implicitly bound — int-backed segments never reach `tryFrom`. */
    private static function isStringBackedEnum(string $fqcn): bool
    {
        if (! is_subclass_of($fqcn, BackedEnum::class)) {
            return false;
        }

        return (new ReflectionEnum($fqcn))->getBackingType()?->getName() === 'string';
    }

    /** Says the binding itself went untyped, without guessing why nothing answered for it. */
    private function reportUntypedBinding(RouteContext $context, string $name, string $modelFqcn): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'route-binding.untyped',
            message: sprintf(
                '{%s} is bound to %s, which nothing enabled could type, so the parameter is documented as a plain string.',
                $name,
                $modelFqcn,
            ),
            routeSignature: $context->route->signature($context->httpMethod()),
            help: sprintf('Declare the segment\'s type with #[PathParameter(\'%s\', type: …)] on the action, or bind a string-backed enum or an Eloquent model with the eloquent integration enabled.', $name),
        ));
    }

    /** Says which column went untyped, and why the parameter is a bare string because of it. */
    private function reportUntypedColumn(RouteContext $context, string $name, string $modelFqcn, string $field): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'route-binding.column-untyped',
            message: sprintf(
                '{%s:%s} binds on %s::$%s, whose type could not be recovered; the parameter is documented as a plain string.',
                $name,
                $field,
                $modelFqcn,
                $field,
            ),
            routeSignature: $context->route->signature($context->httpMethod()),
            help: sprintf('Add a `@property` docblock tag for `$%s` on the model (or a `$casts` entry) so the column\'s type reaches the path parameter.', $field),
        ));
    }

    /** Records the model's file as a cache dependency, when it can be reflected. */
    private function recordModelFile(RouteContext $context, string $modelFqcn): void
    {
        if (! class_exists($modelFqcn)) {
            return;
        }

        $file = (new \ReflectionClass($modelFqcn))->getFileName();
        if ($file !== false) {
            $context->recordDependencyFiles([$file]);
        }
    }
}
