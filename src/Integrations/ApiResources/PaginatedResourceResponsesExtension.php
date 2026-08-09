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
     * The shared terminal table plus any custom Query-Builder terminals from
     * `integrations.query_builder.pagination_terminals` — a collection paginated through a custom
     * terminal like `paginateList` gets the same envelope, so it stays consistent with its QB parameters.
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
