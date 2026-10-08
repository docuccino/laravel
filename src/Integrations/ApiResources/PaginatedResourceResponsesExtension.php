<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\PaginatedResponseBody;
use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;
use Docuccino\Laravel\Support\FrameworkClasses;
use Docuccino\Laravel\Support\IgnoredResponses;

/**
 * Documents the envelope a resource-collection response is sent in. Since the static return type is
 * identical paginated or not, it traces for a paginating terminal and rewraps the body in the
 * `{data, links, meta}` envelope like `UserResource::collection($query->paginate())` sends, for whatever
 * kind turns up. Where none does and the walk proves the collection wraps a plain list
 * ({@see WrappedCollectionVisitor}), the body is restated with the collection's `with()` read for that
 * list alone ({@see WrappedResource}) — its paginator branches are not what this route sends.
 *
 * Runs LATE so the inference-layer body already exists, and writes at integration precedence so it
 * overrides that body while docblocks/attributes still override this. A JSON:API collection is paged the
 * same way.
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
        $by = Contribution::integration('api-resources', $context->actionSource());

        $collection = PaginatedResponseBody::resourceCollectionReturn($context);
        if ($collection !== null) {
            $visitor = new PaginationTerminalVisitor(PaginationTerminalVisitor::terminalsFor($context));
            $context->trace($visitor);

            if ($visitor->paginates && $visitor->kind !== null) {
                PaginatedResponseBody::wrap($operation, $context, $collection, $visitor->kind, $by, $visitor->builtByLaravel());

                return;
            }
        }

        $this->restatePlainList($operation, $context, $by);
    }

    /**
     * Restates the 200 body as the plain list the walk proved the returned collection wraps, where reading
     * its `with()` for that list changes what is published. Only a collection declaring its own `with()`
     * can, and only a body that is the collection's alone is restated.
     */
    private function restatePlainList(OperationDraft $operation, RouteContext $context, Contribution $by): void
    {
        $collection = self::collectionReturn($context);
        if ($collection === null
            || ! $operation->hasResponse('200')
            || in_array(ResourceReflector::declaringClass($collection->fqcn, 'with'), [null, ResourceReflector::JSON_RESOURCE], true)
        ) {
            return;
        }

        $visitor = new WrappedCollectionVisitor($collection->fqcn);
        $context->trace($visitor);
        if (! $visitor->wrapsPlainList() || IgnoredResponses::drops($context, '200')) {
            return;
        }

        $converter = $context->converter();
        $plain = WrappedResource::during($converter, WrappedResource::PLAIN, static fn () => $converter->toSchema($collection))->schema;
        if ($plain === $converter->toSchema($collection)->schema) {
            return;
        }

        $response = $operation->response('200');
        $response->content($response->primaryMediaType() ?: 'application/json')->declareShape($plain, $by);
    }

    /**
     * The resource collection every return sends, bare or rendered through `->response()`; null where any
     * return sends something else, whose body the collection's is only part of.
     */
    private static function collectionReturn(RouteContext $context): ?ClassT
    {
        $collection = null;
        foreach ($context->analysis()->returns as $return) {
            $type = FrameworkClasses::selfRendered($return->type);
            if (! $type instanceof ClassT
                || ! ResourceReflector::isCollection($type->fqcn)
                || ($collection !== null && $collection->canonicalKey() !== $type->canonicalKey())
            ) {
                return null;
            }
            $collection = $type;
        }

        return $collection;
    }
}
