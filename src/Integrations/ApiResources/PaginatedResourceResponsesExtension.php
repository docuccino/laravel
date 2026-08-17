<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\PaginatedResponseBody;
use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;

/**
 * Documents the `{data, links, meta}` envelope on a paginated resource-collection response like
 * `UserResource::collection($query->paginate())`. Since the static return type is identical paginated or
 * not, it traces for a paginating terminal and rewraps the body in the envelope for whatever kind turns
 * up. Runs LATE so the inference-layer body already exists, and writes at integration precedence so it
 * overrides that body while docblocks/attributes still override this. JSON:API collections are excluded.
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class PaginatedResourceResponsesExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Responses;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $collection = PaginatedResponseBody::resourceCollectionReturn($context);
        if ($collection === null) {
            return;
        }

        $visitor = new PaginationTerminalVisitor(PaginationTerminalVisitor::terminalsFor($context));
        $context->trace($visitor);

        if (! $visitor->paginates || $visitor->kind === null) {
            return;
        }

        PaginatedResponseBody::wrap(
            $operation,
            $context,
            $collection,
            $visitor->kind,
            Contribution::integration('api-resources', $context->actionSource()),
        );
    }
}
