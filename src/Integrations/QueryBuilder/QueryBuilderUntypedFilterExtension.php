<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\SchemaDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;

/**
 * Reports a filter the application's own code handles ({@see UntypedFilters}) that the document ends up
 * publishing with no type at all, since a parameter claiming nothing becomes an untyped value at every
 * call site of a generated client.
 *
 * The claim is about the OUTCOME, so it is read off the parameter draft as it finally stands — a rule, a
 * docblock or the very attribute this help asks for still lands on that parameter behind the integration
 * rung (docs/design/defect-classes.md §"A diagnostic that asserts an outcome it never reads"). Hence a
 * pass of its own at `Finalize` and `LAST`, which also keeps it quiet about a filter `#[IgnoreParam]`
 * dropped. Only an overlay comes later, and `diagnostics.accept` answers that.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class QueryBuilderUntypedFilterExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        foreach (UntypedFilters::recorded($context) as $parameter => $filters) {
            foreach ($filters as $filter) {
                $schema = $this->publishedSchema($operation, $context, $parameter, $filter);

                if ($schema === null || ! $schema->saysNothingAboutTheInstance()) {
                    continue;
                }

                $context->components->addDiagnostic(new Diagnostic(
                    severity: Severity::Info,
                    code: 'query-builder.untyped-filter',
                    message: sprintf('Filter "%s" is handled by your own code and nothing types its value, so it is documented with no type at all.', $filter),
                    routeSignature: $context->route->signature(),
                    help: sprintf('Add #[QueryParameter(type: \'string\')] to the filter class, or to the action, to give "%s" a documented type.', $filter),
                ));
            }
        }
    }

    /**
     * The schema the document publishes for one filter, or null where no such node exists — the recorded
     * parameter, and within it the property a deepObject representation nests it under
     * ({@see QueryBuilderParameters::filterParameter()}).
     */
    private function publishedSchema(OperationDraft $operation, RouteContext $context, string $parameter, string $filter): ?SchemaDraft
    {
        if (! $operation->hasParameter('query', $parameter)) {
            return null;
        }

        $schema = $operation->parameter('query', $parameter)->schema();

        if (! $context->representation()->filtersDeepObject()) {
            return $schema;
        }

        return $schema->hasProperty($filter) ? $schema->property($filter) : null;
    }
}
