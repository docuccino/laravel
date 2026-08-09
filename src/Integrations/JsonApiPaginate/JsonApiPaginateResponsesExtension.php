<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\PaginatedResponseBody;
use Docuccino\Laravel\Integrations\Support\PaginationEnvelope;
use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;

/**
 * Documents the response envelope on a `jsonPaginate()` list endpoint whose body is a resource collection.
 * `jsonPaginate()` returns a standard Laravel paginator, so the envelope is the shared
 * {@see PaginationEnvelope} — but length/simple/cursor is decided by the package config
 * (`use_simple_pagination`/`use_cursor_pagination`), not by the terminal name, so the kind comes from
 * {@see JsonApiPaginateConfig::$mode}. Rewraps at integration precedence, matching the parameter side.
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class JsonApiPaginateResponsesExtension implements OperationExtension
{
    public function __construct(
        private readonly JsonApiPaginateConfig $config = new JsonApiPaginateConfig,
    ) {}

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

        $visitor = new PaginationTerminalVisitor([$this->config->methodName => $this->config->mode]);
        $context->trace($visitor);

        if (! $visitor->paginates || $visitor->kind === null) {
            return;
        }

        PaginatedResponseBody::wrap(
            $operation,
            $context,
            $collection,
            $visitor->kind,
            Contribution::integration('json-api-paginate', $context->actionSource()),
        );
    }
}
