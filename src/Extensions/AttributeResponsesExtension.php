<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\ResponseHeader;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\TypeGrammar\ImportContext;
use Docuccino\Core\TypeGrammar\TypeStringParser;

/**
 * Applies the response attributes as the attribute layer: `#[Response]` (per status, with a
 * parsed body type), `#[IgnoreResponse]` removals, and `#[ResponseHeader]` (grouped and merged per
 * status). Examples are the core attribute-examples extension's, which runs once every response exists.
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

        foreach ($context->attributes->all(Response::class) as $attribute) {
            $status = (string) $attribute->status;
            $response = $operation->response($status);

            $response->setDescription('OK', Contribution::fallback());
            $response->setDescription($attribute->description, Contribution::attribute($context->actionSource()));

            if ($attribute->type === null) {
                continue;
            }

            // Unlike the idiomatic `response()->json(null, 204)` inference drops in silence, naming a body
            // AND a bodyless status in one attribute is a deliberate statement that can't be honoured.
            if ($response->isBodyless()) {
                $this->reportBodylessBody($context, $status, $attribute->type);

                continue;
            }

            $schema = $context->converter()->toSchema($this->types->parse($attribute->type, $imports))->schema;
            foreach ($schema as $keyword => $value) {
                $response->content($attribute->mediaType)->set($keyword, $value, Contribution::attribute($context->actionSource()));
            }
        }

        $this->applyResponseHeaders($operation, $context, $imports);
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
            $operation->response((string) $status)->set('headers', $headers, Contribution::attribute($context->actionSource()));
        }
    }
}
