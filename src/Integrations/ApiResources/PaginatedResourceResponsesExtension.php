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
 * Documents the `{data, links, meta}` envelope on a paginated API-resource collection response
 * (`UserResource::collection($query->paginate())` — audit api-resources #3). The static return type
 * (`AnonymousResourceCollection<T>`) is identical paginated or not, so it traces the action for a
 * paginating terminal ({@see PaginationTerminalVisitor}, so the walk's dependency files join the
 * fragment cache key) and, when one is reached, rewraps the collection body in the paginator envelope
 * for the recovered kind (length/simple/cursor). Runs LATE so the inference-layer body already exists;
 * writes at integration precedence so it overrides that body (and docblocks/attributes still override
 * this). JSON:API collections have their own envelope and are excluded.
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

        $visitor = new PaginationTerminalVisitor($this->terminals($context));
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

    /**
     * The paginating terminals that trigger the resource envelope: the shared length/simple/cursor
     * table plus any custom Query-Builder terminals configured under
     * `integrations.query_builder.pagination_terminals` (arch PIN 3 — a resource collection paginated
     * via a custom QB terminal such as `paginateList` earns the `{data, links, meta}` envelope too,
     * length-aware, so it is documented consistently with its QB page parameters).
     *
     * @return array<string, string>
     */
    private function terminals(RouteContext $context): array
    {
        $terminals = PaginationTerminalVisitor::PAGINATOR_TERMINALS;

        $custom = $context->document->integration('query_builder')['pagination_terminals'] ?? null;
        if (is_array($custom)) {
            foreach ($custom as $terminal) {
                if (is_string($terminal)) {
                    $terminals[$terminal] ??= 'length';
                }
            }
        }

        return $terminals;
    }
}
