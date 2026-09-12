<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Draft\SchemaDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Validation\DeepObjectMembers;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\TypeGrammar\TypeStringParser;
use Docuccino\Laravel\Support\UnmatchedDeclaration;

/**
 * Applies the parameter attributes at the attribute precedence layer (design §7): query, header,
 * cookie and explicit path parameters. Type strings are parsed to a DType and converted through the
 * route's schema chain, so `#[QueryParameter(type: 'int')]` gives an integer schema. The subtractive
 * `#[IgnoreParam]` is {@see IgnoredParametersExtension}, which has to run after every producer.
 *
 * A `#[PathParameter]` naming no segment of the route template is withheld and reported rather than
 * minted — see {@see applyPathParameters()}, the one member here whose name cannot create what it names.
 *
 * A bracketed name (`#[QueryParameter('filter[status]')]`) patches the matching property of a deepObject
 * container parameter when one exists — type/description/format/example/default onto the property
 * schema, a stated `required` onto or off the container's `required` list — so the deepObject and flat
 * bracketed representations behave identically. Which container a bracketed name belongs to is
 * {@see DeepObjectMembers}'s reading, shared with the producer that recovers query parameters from
 * validation rules, so one name cannot land in two places depending on who wrote it.
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
        $members = new DeepObjectMembers($operation);
        foreach ($context->attributes->all(QueryParameter::class) as $attribute) {
            $property = $members->schemaFor($attribute->name);
            if ($property === null) {
                $parameter = $operation->parameter('query', $attribute->name);
                $this->apply($parameter, $context, $attribute->type, $attribute->description, $attribute->format, $attribute->required, $attribute->default, $attribute->example);

                continue;
            }

            $this->applyToProperty($property, $context, $attribute->type, $attribute->description, $attribute->format, $attribute->default, $attribute->example);
            $members->stateRequired($attribute->name, $attribute->required);
        }
        $members->flush(Contribution::attribute($context->actionSource()));

        foreach ($context->attributes->all(HeaderParameter::class) as $attribute) {
            $parameter = $operation->parameter('header', $attribute->name);
            $this->apply($parameter, $context, $attribute->type, $attribute->description, $attribute->format, $attribute->required, null, $attribute->example);
        }

        foreach ($context->attributes->all(CookieParameter::class) as $attribute) {
            $parameter = $operation->parameter('cookie', $attribute->name);
            $this->apply($parameter, $context, $attribute->type, $attribute->description, $attribute->format, $attribute->required, null, $attribute->example);
        }

        $this->applyPathParameters($operation, $context);
    }

    /**
     * The path attributes, which are the one kind that cannot MINT what it names: OAS requires every
     * `in: path` parameter to correspond to a template variable, so a name outside the route's own URI
     * would publish a document a validator rejects rather than no-op.
     *
     * So it is withheld rather than published, for every declaration and not only the reported ones.
     * That is the opposite call to an unresolvable security requirement, which is kept and reported
     * because dropping it would claim an endpoint is unauthenticated — a true fact with nowhere to
     * point. This parameter states nothing true: no request can carry it, because the URI has no place
     * to put it. Withholding therefore costs the consumer nothing and keeps the document valid.
     *
     * The report is for the ACTION's own declarations only. One on a controller covering a segment some
     * of its actions have is the ordinary way a class-level declaration is written, and an action
     * without that segment is not a mistake — the same scoping, for the same measurement, that
     * `#[IgnoreParam]`'s unmatched report uses. Withholding covers the inherited case regardless, so
     * silence there costs the document nothing.
     */
    private function applyPathParameters(OperationDraft $operation, RouteContext $context): void
    {
        $direct = $context->attributes->direct(PathParameter::class);
        $reported = [];

        foreach ($context->attributes->all(PathParameter::class) as $attribute) {
            if (! in_array($attribute->name, $context->pathParameters, true)) {
                // Deduped: two declarations naming one missing segment are one mistake, and saying it
                // twice sends the reader looking for a second one.
                if (in_array($attribute, $direct, true) && ! in_array($attribute->name, $reported, true)) {
                    $reported[] = $attribute->name;

                    $context->components->addDiagnostic(UnmatchedDeclaration::pathParameter(
                        $attribute->name,
                        $context->pathParameters,
                        $context->actionSource(),
                        $context->route->signature(),
                    ));
                }

                continue;
            }

            $parameter = $operation->parameter('path', $attribute->name);
            $this->apply($parameter, $context, $attribute->type, $attribute->description, $attribute->format, true, null, $attribute->example);
        }
    }

    private function apply(
        ParameterDraft $parameter,
        RouteContext $context,
        ?string $type,
        ?string $description,
        ?string $format,
        ?bool $required,
        mixed $default,
        mixed $example,
    ): void {
        $contribution = Contribution::attribute($context->actionSource());

        // A null `required` is the absent argument, and the guard reads null as "not specified": a
        // declaration written to document a type must not de-require a parameter an integration
        // proved. A written `false` is the author's own statement and outranks that recovery.
        $parameter->setRequired($required, $contribution);
        $parameter->setDescription($description, $contribution);

        if ($type !== null) {
            $parameter->schema()->declareShape($context->converter()->toSchema($this->types->parseDeclared($type))->schema, $contribution);
        }

        // After the type keywords, so an explicit format wins over one the type string implied.
        if ($format !== null) {
            $parameter->schema()->set('format', $format, $contribution);
        }

        if ($default !== null) {
            $parameter->schema()->set('default', $default, $contribution);
        }

        if ($example !== null) {
            $parameter->set('example', $example, $contribution);
        }
    }

    /** Type/description/format/default/example onto a deepObject property's schema. */
    private function applyToProperty(
        SchemaDraft $property,
        RouteContext $context,
        ?string $type,
        ?string $description,
        ?string $format,
        mixed $default,
        mixed $example,
    ): void {
        $contribution = Contribution::attribute($context->actionSource());

        if ($type !== null) {
            $property->declareShape($context->converter()->toSchema($this->types->parseDeclared($type))->schema, $contribution);
        }
        if ($format !== null) {
            $property->set('format', $format, $contribution);
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
}
