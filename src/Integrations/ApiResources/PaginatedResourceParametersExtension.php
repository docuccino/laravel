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
use Docuccino\Laravel\Integrations\Support\PaginatorPageParameter;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * The request half of a paginated resource collection: off the same trace that
 * {@see PaginatedResourceResponsesExtension} rewraps the body with, the query parameter that paginator
 * kind actually reads. Without it the document tells a consumer they are on page 3 of 12 and never
 * tells them how to ask for page 4.
 *
 * Runs last in the phase so it sees what every other parameter producer contributed: a chain the
 * Query-Builder or json-api-paginate integration already documented keeps that one parameter, and so
 * does a key the author pinned themselves. Writes at the integration layer, so a later docblock,
 * overlay or config still overrides it field by field.
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class PaginatedResourceParametersExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if (PaginatedResponseBody::resourceCollectionReturn($context) === null) {
            return;
        }

        $visitor = new PaginationTerminalVisitor(PaginationTerminalVisitor::terminalsFor($context));
        $context->trace($visitor);

        if (! $visitor->paginates || $visitor->kind === null) {
            return;
        }

        $contribution = Contribution::integration('api-resources', $context->actionSource());

        $spec = PaginatorPageParameter::forTerminal($visitor->terminal, $visitor->kind, $visitor->outermostArgs);
        if ($spec !== null && ! $this->alreadyStated($operation, $spec, $visitor->kind)) {
            $spec->applyTo($operation->parameter('query', $spec->name), $contribution);
        }

        // The size key rides the same trace, and only where it was proven — a chain sized at its call site
        // states nothing here, exactly as the Query-Builder producer states nothing.
        $size = $visitor->pageSize();
        if ($size !== null && ! $operation->hasParameter('query', $size->key)) {
            PaginatorPageParameter::size($size)->applyTo(
                $operation->parameter('query', $size->key),
                $contribution,
            );
        }

        // Last, because a size key's own file is only known once the recovery has run — the reader resolves
        // before it answers, so this cannot come back to depending on the order of these two calls.
        $context->recordDependencyFiles($visitor->dependencyFiles());
    }

    /**
     * True when this operation already states a page selector — under the name this one would use, or
     * under the framework default some other producer would have used. Either way a second key can only
     * contradict the first, and a document that names two ways to page is worse than one that names one.
     */
    private function alreadyStated(OperationDraft $operation, QueryParameterSpec $spec, string $kind): bool
    {
        return $operation->hasParameter('query', $spec->name)
            || $operation->hasParameter('query', PaginatorPageParameter::for($kind)->name);
    }
}
