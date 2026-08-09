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

/**
 * Re-homes a bare single-resource success response from 200 to 201 when the action returns a resource
 * wrapped directly around a freshly `create()`d model ({@see CreatedResourceVisitor} — audit
 * api-resources #12): Laravel's `ResourceResponse::calculateStatus()` returns 201 for a
 * `wasRecentlyCreated` model. Runs LATE so the inferred 200 already exists; re-converts the resource
 * body under 201 (same media type) and drops the 200. Only the statically-clear create-wrap is
 * recovered — anything less degrades silently to the default 200. Yields to an explicit 201.
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class CreatedResourceResponsesExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Responses;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $resource = $this->singleResourceReturn($context);
        if ($resource === null || ! $operation->hasResponse('200') || $operation->hasResponse('201')) {
            return;
        }

        $visitor = new CreatedResourceVisitor;
        $context->trace($visitor);
        if (! $visitor->created) {
            return;
        }

        $mediaType = $operation->response('200')->primaryMediaType() ?: 'application/json';
        $result = $context->converter()->toSchema($resource);
        $by = Contribution::integration('api-resources', $context->actionSource());

        $created = $operation->response('201');
        $created->setDescription('Created', $by);
        foreach ($result->schema as $keyword => $value) {
            $created->content($mediaType)->set($keyword, $value, $by);
        }

        $operation->removeResponse('200');
    }

    /** The action's single-resource (non-collection) return type, or null when it returns none. */
    private function singleResourceReturn(RouteContext $context): ?ClassT
    {
        foreach ($context->analysis()->returns as $return) {
            $type = $return->type;
            if ($type instanceof ClassT
                && ResourceReflector::isResource($type->fqcn)
                && ! ResourceReflector::isAnonymousCollection($type->fqcn)
            ) {
                return $type;
            }
        }

        return null;
    }
}
