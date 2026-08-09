<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\Example;
use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\ResponseHeader;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\TypeGrammar\ImportContext;
use Docuccino\Core\TypeGrammar\TypeStringParser;

/**
 * Applies the response attributes as the attribute layer: `#[Response]` (per status, with a
 * parsed body type), `#[IgnoreResponse]` removals, `#[ResponseHeader]` (grouped and merged per
 * status), and method-level `#[Example]` (attached to the success response body).
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

            if ($attribute->type !== null) {
                $schema = $context->converter()->toSchema($this->types->parse($attribute->type, $imports))->schema;
                foreach ($schema as $keyword => $value) {
                    $response->content($attribute->mediaType)->set($keyword, $value, Contribution::attribute($context->actionSource()));
                }
            }
        }

        $this->applyResponseHeaders($operation, $context, $imports);
        $this->applyExamples($operation, $context);
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

    private function applyExamples(OperationDraft $operation, RouteContext $context): void
    {
        foreach ($context->attributes->all(Example::class) as $example) {
            if ($example->value === null) {
                continue;
            }

            $operation->response('200')->content('application/json')->set('example', $example->value, Contribution::attribute($context->actionSource()));

            return; // the first concrete example wins for the success body
        }
    }
}
