<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Webhooks;

use Docuccino\Attributes\DeprecatedOperation;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\Internal;
use Docuccino\Attributes\Response;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Contracts\TypeSchemaConverter;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Provenance\Source;
use Docuccino\Core\TypeGrammar\ImportContext;
use Docuccino\Core\TypeGrammar\TypeStringParser;

/**
 * Turns one {@see WebhookDeclaration} into the operation published under `webhooks`: the delivered
 * body as the request body, an acknowledgement response, and the class's docblock and attributes on
 * top.
 *
 * The body is the annotated class read through the ordinary type machinery, so a webhook payload is
 * documented by whatever already documents that class — a Data object, a resource, a plain DTO.
 *
 * @internal
 */
final readonly class WebhookOperationBuilder
{
    /** What a receiver answers when it accepted the delivery, absent a `#[Response]` saying otherwise. */
    private const ACK_STATUS = '200';

    public function __construct(
        private TypeStringParser $types = new TypeStringParser,
    ) {}

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    public function build(
        WebhookDeclaration $webhook,
        DocumentConfig $document,
        TypeSchemaConverter $converter,
        ?Source $source,
        array &$diagnostics,
    ): OperationDraft {
        $operation = new OperationDraft;
        $attribute = Contribution::attribute($source);
        // Unqualified class names in a type string resolve against the webhook file's own imports, so
        // an author writes the name they already wrote in that file.
        $imports = ImportContext::forFile($webhook->files[0] ?? null);

        $operation->setOperationId($webhook->name, Contribution::fallback($source));
        $operation->setSummary($webhook->summary, Contribution::docblock($source));
        $operation->setDescription($webhook->description, Contribution::docblock($source));

        $this->applyBody($operation, $webhook, $converter, $imports, $source, $diagnostics);
        $this->applyResponses($operation, $webhook, $converter, $imports, $source);

        $tags = $this->tags($webhook, $document);
        if ($tags !== []) {
            $operation->setTags($tags, $attribute);
        }

        if ($webhook->attributes->has(DeprecatedOperation::class)) {
            $operation->setDeprecated(true, $attribute);
        }

        if ($webhook->attributes->has(Internal::class)) {
            $operation->set('x-internal', true, $attribute);
        }

        return $operation;
    }

    /**
     * The delivered payload, read through the ordinary type machinery.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    private function applyBody(
        OperationDraft $operation,
        WebhookDeclaration $webhook,
        TypeSchemaConverter $converter,
        ImportContext $imports,
        ?Source $source,
        array &$diagnostics,
    ): void {
        $type = $this->types->parse($webhook->payload, $imports);

        if ($type instanceof UnknownT) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'webhook.payload-unresolved',
                message: sprintf(
                    'The webhook "%s" names the payload type "%s", which resolves to no shape — its body is documented as an unconstrained object.',
                    $webhook->name,
                    $webhook->payload,
                ),
                source: $source,
                routeSignature: $webhook->signature(),
                help: 'Name a class or an array shape the payload is built from, or drop the payload argument to document the annotated class itself.',
            );
        }

        $operation->set('requestBody', [
            'required' => true,
            'content' => [$webhook->mediaType => ['schema' => $converter->toSchema($type)->schema]],
        ], Contribution::attribute($source));
    }

    /**
     * The receiver's side of the contract: what it is expected to answer. One acknowledgement by
     * default, and `#[Response]` on the webhook class documents any other status the sender acts on.
     */
    private function applyResponses(
        OperationDraft $operation,
        WebhookDeclaration $webhook,
        TypeSchemaConverter $converter,
        ImportContext $imports,
        ?Source $source,
    ): void {
        $operation->response(self::ACK_STATUS)->setDescription(
            'Delivery accepted.',
            Contribution::fallback($source),
        );

        $attribute = Contribution::attribute($source);

        foreach ($webhook->attributes->all(Response::class) as $declared) {
            $response = $operation->response((string) $declared->status);
            $response->setDescription('OK', Contribution::fallback($source));
            $response->setDescription($declared->description, $attribute);

            if ($declared->type === null || $response->isBodyless()) {
                continue;
            }

            $schema = $converter->toSchema($this->types->parse($declared->type, $imports))->schema;
            foreach ($schema as $keyword => $value) {
                $response->content($declared->mediaType)->set($keyword, $value, $attribute);
            }
        }
    }

    /**
     * `#[Group]` only. A webhook has no controller, so the document's default tag strategy — which is
     * about controller names — has nothing to derive a tag from.
     *
     * @return list<string>
     */
    private function tags(WebhookDeclaration $webhook, DocumentConfig $document): array
    {
        $tags = [];

        foreach ($webhook->attributes->all(Group::class) as $group) {
            $mapped = $document->mapTag($group->name);
            if (! in_array($mapped, $tags, true)) {
                $tags[] = $mapped;
            }
        }

        return $tags;
    }
}
