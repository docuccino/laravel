<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use BackedEnum;
use Docuccino\Attributes\PathParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Contracts\RouteBindingFieldSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingKeyResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Support\PortablePattern;
use Docuccino\Laravel\Routing\RouteConstraints;
use Docuccino\Laravel\Routing\RouteTemplate;
use Docuccino\Laravel\Support\ListValueNames;
use ReflectionEnum;

/**
 * Adds a path parameter for every `{param}` in the route template. A model-bound parameter is typed
 * from the model's route key (uuid/ulid/int, with format) via the gated
 * {@see RouteBindingSchemaResolver} chain, so `{model}` matches the real key instead of a hardcoded
 * integer; `{model:column}` is typed from THAT column instead, through the same chain. A
 * string-backed-enum hint is Laravel's implicit enum binding, so it types as that enum. An unbound
 * segment — a disabled Eloquent integration, an int-backed or pure enum, a custom `UrlRoutable` —
 * gives a required string, as does a binding whose matching column is a method body, and
 * `#[PathParameter]` can refine any of it from the higher attribute layer.
 *
 * A segment the route constrains (`->where()`, `whereUuid()`, `Route::pattern()`) and nothing typed is
 * published as what the router will match, since any other value is a 404 and never reaches the action:
 * the framework's UUID and ULID expressions as those formats, an alternation of literals (`whereIn()`)
 * as that enum, and any other expression as a `pattern` accepting what it does ({@see RouteConstraints::pattern()}).
 * One no pattern can state truly is left a plain string and reported — a pattern narrower than the
 * router would mark working requests invalid.
 *
 * Every path parameter is required, which is OpenAPI's rule at every version: the path it sits in is only
 * ever this path with the segment present. A route with optional segments is one operation per URL form
 * instead ({@see RouteTemplate::forms()}), and the last optional segment of a form, where nothing but
 * the route typed it, carries the value leaving it off sends as its `default` — sending that value
 * answers exactly as the next shorter form does.
 *
 * A bound parameter also carries what the route and the model settle about how it RESOLVES, which is
 * the half a consumer cannot read off the path: the column the value is matched on, the parent it is
 * scoped to, and whether soft-deleted records resolve. Each goes out twice — as a sentence of the
 * description and as a member of `x-docuccino.facts.routeBinding` — because a client generator reads
 * the fact and a person reads the sentence, and a fact stated only in prose is one no generator can
 * act on. Nothing about the codebase reaches either: the model is not named, because a consumer
 * cannot see it and could do nothing with it if they could.
 *
 * What is NOT settled is left out rather than guessed. A column comes from the route where the route
 * names one (`{post:slug}`) and from the bound model's route key otherwise — but only while nothing
 * has moved that decision into a method body, which an override of the key methods or a binder of the
 * application's own both do. A short description costs a consumer nothing; a wrong one sends them to
 * look records up by the wrong attribute.
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

            // Every OpenAPI version requires a path parameter, since the path it sits in is only this
            // path with the segment present. A shorter form of the route is an operation of its own.
            $parameter->setRequired(true, $contribution);

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
                // The route's constraint answers what the binding could not, and the document then no
                // longer says "a plain string", so a notice saying it does would be false.
                $constraint = $this->constraint($context, $name);
                foreach ($constraint ?? [] as $keyword => $value) {
                    $parameter->schema()->set($keyword, $value, Contribution::inference());
                }

                // A binding nothing could type is a fallback string, not an inferred one — and it is
                // reported, because the route says more about this parameter than the document does.
                $degraded = $isBound;
                if ($constraint === null) {
                    $parameter->schema()->set('type', 'string', $degraded ? Contribution::fallback() : $contribution);
                }

                // The schema is the route's own only while nothing typed the segment, so a bound or
                // declared one keeps its shape and says nothing about a default spelled as a segment.
                $default = $isBound || self::declaresType($context, $name) ? null : ($context->pathParameterDefaults[$name] ?? null);
                if ($default !== null) {
                    $parameter->schema()->set('default', $default, Contribution::inference());
                    // Which path this one then answers exactly as, which no schema keyword can say.
                    $parameter->setDescription(sprintf('Leaving it off is the same as sending `%s`.', $default), $contribution);
                }

                if ($isBound && $constraint === null && ! self::declaresType($context, $name)) {
                    if (self::isCustomBound($context, $name)) {
                        $this->reportCustomBinding($context, $name);
                    } elseif ($field !== null) {
                        $this->reportUntypedColumn($context, $name, $context->routeBindings[$name], $field);
                    } else {
                        $this->reportUntypedBinding($context, $name, $context->routeBindings[$name]);
                    }
                }
            }

            if ($isBound) {
                $this->describeBinding($parameter, $context, $name, $field, $contribution);
            }
        }
    }

    /**
     * What the route's constraint on this segment settles, or null when it settles nothing a schema can
     * say truly — reported, unless the constraint is a catch-all a plain string already describes.
     *
     * @return array<string, mixed>|null
     */
    private function constraint(RouteContext $context, string $name): ?array
    {
        $expression = $context->pathParameterConstraints[$name] ?? null;
        if ($expression === null || in_array($expression, RouteConstraints::CATCH_ALL, true)) {
            return null;
        }

        $schema = self::constraintSchema($expression, $context);
        if ($schema === null) {
            $this->reportUnportableConstraint($context, $name, $expression);
        }

        return $schema;
    }

    /**
     * The schema that says what the expression matches, or null when none says it truly.
     *
     * @return array<string, mixed>|null
     */
    private static function constraintSchema(string $expression, RouteContext $context): ?array
    {
        // The UUID shorthand spells its digits `\d`, which is Unicode-wide under the router's modifiers and
        // so no portable pattern; the format is what the shorthand states, and what a UUID-keyed binding
        // publishes too.
        $format = RouteConstraints::format($expression);
        if ($format !== null) {
            return ['type' => 'string', 'format' => $format];
        }

        $values = PortablePattern::literals($expression);
        if ($values !== null) {
            return EnumDecoration::apply(
                ['type' => 'string', 'enum' => $values],
                $context->representation()->enumNaming,
                ListValueNames::names($values),
                [],
            );
        }

        $pattern = RouteConstraints::pattern($expression);

        return $pattern === null ? null : ['type' => 'string', 'pattern' => $pattern];
    }

    /** Says the route constrains the segment in a way no portable `pattern` can state. */
    private function reportUnportableConstraint(RouteContext $context, string $name, string $expression): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'route-constraint.unportable',
            message: sprintf(
                '{%s} must match the route constraint `%s`, which reads differently as a JSON Schema pattern, so the parameter is documented as a plain string.',
                $name,
                $expression,
            ),
            routeSignature: $context->route->signature($context->httpMethod()),
            help: 'Spell the constraint with literal characters, bracket classes, groups, alternation and quantifiers, and it is published as the parameter\'s `pattern`. '
                .'A count over `\d`, `\w` or a negated class, such as `\d{4}`, counts characters in the router and UTF-16 units in a JSON Schema validator, so write `[0-9]{4}` where ASCII digits are meant.',
        ));
    }

    /**
     * The resolution facts, in a fixed order so the description and the fact map are both functions of
     * the route rather than of the order anything was discovered in.
     */
    private function describeBinding(
        ParameterDraft $parameter,
        RouteContext $context,
        string $name,
        ?string $field,
        Contribution $contribution,
    ): void {
        $notes = [];
        $facts = [];

        $key = $this->matchedColumn($context, $name, $field);
        if ($key !== null) {
            $facts['key'] = $key;
            $notes[] = sprintf('Matched on the resource\'s `%s`.', $key);
        }

        $parent = $context->scopedBindings[$name] ?? null;
        if ($parent !== null) {
            $facts['scopedTo'] = $parent;
            $notes[] = sprintf('Scoped to `{%s}`: only values belonging to it match.', $parent);
        }

        if ($context->allowsTrashedBindings) {
            $facts['withTrashed'] = true;
            $notes[] = self::TRASHED_NOTE;
        }

        if ($notes !== []) {
            $parameter->setDescription(implode(' ', $notes), $contribution);
        }

        if ($facts !== []) {
            $parameter->setDocuccinoFact('routeBinding', $facts);
        }
    }

    /**
     * The column the value is matched on, or null when nothing static settles it.
     *
     * A binder the application registered is asked FIRST and ends the question: the framework runs it
     * before implicit binding and ignores both the route's column and the model's route key, so
     * publishing either would name a column the server never looks at. Otherwise the route's own
     * column wins where it names one, and the bound model's route key answers the rest
     * ({@see RouteBindingKeyResolver}).
     */
    private function matchedColumn(RouteContext $context, string $name, ?string $field): ?string
    {
        if (self::isCustomBound($context, $name)) {
            return null;
        }

        return $field ?? $context->routeBindingKeyName($context->routeBindings[$name]);
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
        // A binder the application registered runs before implicit binding and answers with whatever
        // its closure says, so neither the route's column nor the model's key describes what the
        // segment holds — and the model's key SHAPE would be a precise wrong answer, an `integer` for
        // a username lookup. The registry cannot tell such a binder from a `Route::model()` one, whose
        // key really is the model's, and widening both is the trade the degradation rule names:
        // vagueness costs the one some type safety, precision costs the other a rejected request.
        if (self::isCustomBound($context, $name)) {
            return null;
        }

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

    /** Whether the application registered a binder of its own for this segment. */
    private static function isCustomBound(RouteContext $context, string $name): bool
    {
        return in_array($name, $context->customBoundParameters, true);
    }

    /** Only a string-backed enum is implicitly bound — int-backed segments never reach `tryFrom`. */
    private static function isStringBackedEnum(string $fqcn): bool
    {
        if (! is_subclass_of($fqcn, BackedEnum::class)) {
            return false;
        }

        return (new ReflectionEnum($fqcn))->getBackingType()?->getName() === 'string';
    }

    /**
     * Whether `#[PathParameter]` already names this segment's type. The attribute layer applies it a
     * whole extension later, so the untyped-binding notice would otherwise name a parameter the document
     * types perfectly and send its author at the attribute they had already written. Its remedy is the
     * one thing that settles it, so reading it here is what makes the notice true.
     */
    private static function declaresType(RouteContext $context, string $name): bool
    {
        foreach ($context->attributes->all(PathParameter::class) as $declared) {
            if ($declared->name === $name && $declared->type !== null) {
                return true;
            }
        }

        return false;
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

    /** Says that the application's own binder, not the model, decides what this segment holds. */
    private function reportCustomBinding(RouteContext $context, string $name): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'route-binding.custom-binder',
            message: sprintf(
                '{%s} is resolved by a binder the application registered for it, so what the segment holds is decided in a closure and the parameter is documented as a plain string.',
                $name,
            ),
            routeSignature: $context->route->signature($context->httpMethod()),
            help: sprintf('Declare the segment\'s type with #[PathParameter(\'%s\', type: …)] on the action.', $name),
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

    /**
     * Records the model's declaration as a cache dependency.
     *
     * The whole hierarchy, not just the file the class name points at: the route key, its schema and
     * the soft-delete answer are all DECLARATIONS, and a parent model or a trait answers most of them —
     * a `HasUuids` added to a base class, or a `$primaryKey` moved up one, changes what every subclass
     * publishes while leaving the subclass's own file untouched (design §10).
     */
    private function recordModelFile(RouteContext $context, string $modelFqcn): void
    {
        $context->recordDependencyFiles(DeclarationFiles::of($modelFqcn));
    }
}
