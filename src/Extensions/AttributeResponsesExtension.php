<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\ResponseHeader;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\TypeGrammar\ImportContext;
use Docuccino\Core\TypeGrammar\TypeStringParser;
use Docuccino\Laravel\Support\IgnoredResponses;

/**
 * Applies the response attributes as the attribute layer: `#[Response]` (per status, with a
 * parsed body type), `#[IgnoreResponse]` removals, and `#[ResponseHeader]` (grouped and merged per
 * status). Examples are the core attribute-examples extension's, which runs once every response exists.
 *
 * The removal is a BACKSTOP and not the mechanism: every built-in producer consults
 * {@see IgnoredResponses} before it converts anything, because a response removed after its body was
 * converted leaves the components that body hoisted behind. What the sweep still catches is a producer
 * this package does not own — a third-party extension in an earlier phase — where an orphan is the
 * lesser of the two defects. This extension's own writes below consult like every other producer.
 */
final class AttributeResponsesExtension implements OperationExtension
{
    public function __construct(
        private readonly TypeStringParser $types = new TypeStringParser,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Responses;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        // Unqualified class names in `type:` strings resolve against the controller file's imports and
        // namespace, so authors don't have to write FQCNs to get a real class instead of a bare object.
        $imports = ImportContext::forFile($context->actionRef->file === '' ? null : $context->actionRef->file);

        foreach ($context->attributes->all(IgnoreResponse::class) as $ignore) {
            $operation->removeResponse((string) $ignore->status);
        }

        $this->reportIllegalComponents($context);

        foreach ($context->attributes->all(Response::class) as $attribute) {
            $status = (string) $attribute->status;

            // Same action, same layer: the ignore is the retraction, so it wins over the declaration —
            // and it wins BEFORE the declared type is parsed and converted, which is where a named class
            // would hoist a component nothing would then reference.
            if (IgnoredResponses::drops($context, $status)) {
                continue;
            }

            $response = $operation->response($status);

            // Naming a code is also a statement about the range inference put it in
            // ({@see OperationDraft::supersedeStatusRange()}).
            $operation->supersedeStatusRange($status, Contribution::attribute($context->actionSource()));

            $response->setDescription('OK', Contribution::fallback());
            $response->setDescription($attribute->description, Contribution::attribute($context->actionSource()));
            $this->claimDeclaredComponent($response, $attribute, $context);

            if ($attribute->type === null) {
                continue;
            }

            // Unlike the idiomatic `response()->json(null, 204)` inference drops in silence, naming a body
            // AND a bodyless status in one attribute is a deliberate statement that can't be honoured.
            if ($response->isBodyless()) {
                $this->reportBodylessBody($context, $status, $attribute->type);

                continue;
            }

            // Naming the media type is the same statement one level down
            // ({@see ResponseDraft::supersedeMediaRange()}) — but only a media type the author actually
            // WROTE is a naming. The default publishes the body under JSON without claiming the stream a
            // producer could only document as any-media-type is really JSON, which nobody said.
            if ($attribute->mediaType !== null) {
                $response->supersedeMediaRange($attribute->mediaType, Contribution::attribute($context->actionSource()));
            }

            $mediaType = $attribute->mediaType ?? Response::DEFAULT_MEDIA_TYPE;

            // One declared shape, not a keyword-by-keyword patch: whatever a producer worked out about
            // the body it replaces comes off with it (SchemaDraft::declareShape()).
            $schema = $context->converter()->toSchema($this->types->parse($attribute->type, $imports))->schema;
            $response->content($mediaType)->declareShape($schema, Contribution::attribute($context->actionSource()));
        }

        $this->applyResponseHeaders($operation, $context, $imports);
    }

    /**
     * The name a `#[Response]` declared, through the `claimComponentName()` every producer uses — the
     * anchor for an error body an operation states itself, which `#[ErrorComponent]` cannot reach. Both
     * arguments are stated there: `namesResponse:` because a declaration at the operation can see every
     * representation the status answers with, and `specificity: 1` to put "the declaration nearest the
     * operation wins" into the tuple the guard compares rather than leave it to phase order.
     */
    private function claimDeclaredComponent(ResponseDraft $response, Response $attribute, RouteContext $context): void
    {
        $response->claimComponentName(
            $attribute->errorComponent,
            Contribution::attribute($context->actionSource(), specificity: 1),
            namesResponse: true,
        );
    }

    /**
     * One warning per `errorComponent:` no `$ref` could point at, under the code
     * {@see ErrorResponsesExtension::reportIllegalName()} raises for the anchor next door — one mistake,
     * one remedy, and only the declaration to go and fix differs. Keyed by the mistake rather than the
     * declaration, and sorted, so what the route says never depends on attribute order.
     */
    private function reportIllegalComponents(RouteContext $context): void
    {
        /** @var array<string, array{string, string}> $illegal */
        $illegal = [];
        foreach ($context->attributes->all(Response::class) as $attribute) {
            $name = $attribute->errorComponent;
            if ($name === null || ComponentNames::isLegal($name)) {
                continue;
            }

            $status = (string) $attribute->status;
            if (IgnoredResponses::drops($context, $status)) {
                continue;
            }

            $illegal[$status."\0".$name] = [$status, $name];
        }

        ksort($illegal);

        foreach ($illegal as [$status, $name]) {
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Warning,
                code: 'attribute.error-component-invalid',
                message: sprintf(
                    '#[Response(status: %s, errorComponent: "%s")] is not a name an OpenAPI component key can carry, so the argument names nothing and the response keeps the name it would have had.',
                    $status,
                    $name,
                ),
                source: $context->actionSource(),
                routeSignature: $context->route->signature(),
                help: ComponentNames::LEGAL_NAME_HELP,
            ));
        }
    }

    private function reportBodylessBody(RouteContext $context, string $status, string $type): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.body-on-bodyless-status',
            message: sprintf('#[Response(status: %s, type: %s)] names a body under a status HTTP forbids one on; the body is not documented.', $status, $type),
            source: $context->actionSource(),
            routeSignature: $context->route->signature(),
            help: 'Document the body under a status that may carry one, or drop `type:` — 1xx, 204, 205 and 304 responses never carry content (RFC 9110).',
        ));
    }

    private function applyResponseHeaders(OperationDraft $operation, RouteContext $context, ImportContext $imports): void
    {
        /** @var array<string, array<string, array<string, mixed>>> $byStatus */
        $byStatus = [];
        foreach ($context->attributes->all(ResponseHeader::class) as $header) {
            $status = (string) $header->status;
            if (IgnoredResponses::drops($context, $status)) {
                continue;
            }

            $schema = $header->type !== null
                ? $context->converter()->toSchema($this->types->parse($header->type, $imports))->schema
                : ['type' => 'string'];

            $entry = ['schema' => $schema];
            if ($header->description !== null) {
                $entry['description'] = $header->description;
            }

            $headers = $byStatus[$status] ?? [];
            $headers[$header->name] = $entry;
            $byStatus[$status] = $headers;
        }

        foreach ($byStatus as $status => $headers) {
            // Naming a header AT a status is a statement that the status exists, so it retires the range
            // the same way #[Response] does ({@see OperationDraft::supersedeStatusRange()}).
            $operation->supersedeStatusRange((string) $status, Contribution::attribute($context->actionSource()));

            // `headers` is one guarded field every producer writes whole, so a declaration has to carry
            // what is already there or it replaces it — the declared header BESIDE a redirect's inherited
            // `Location`, not instead of it. A name written twice is the author's.
            $response = $operation->response((string) $status);
            $inherited = $response->resolvedField('headers');

            $response->set(
                'headers',
                is_array($inherited) ? [...$inherited, ...$headers] : $headers,
                Contribution::attribute($context->actionSource()),
            );
        }
    }
}
