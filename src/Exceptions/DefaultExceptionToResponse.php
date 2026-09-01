<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Exceptions;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;

/**
 * The terminal {@see ExceptionToResponse}: any signalled exception gets a generic `{message: string}`
 * body, so error docs are never empty when nothing more specific matched. Pinned last so specific
 * mappers always win. Reason phrases come from the shared {@see FrameworkExceptionTable} so this tier
 * can't drift from the others on a status's label.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class DefaultExceptionToResponse implements ExceptionToResponse
{
    public function supports(ThrownException $exception, RouteContext $context): bool
    {
        return true;
    }

    public function producer(): string
    {
        return 'fallback';
    }

    public function toResponse(
        ThrownException $exception,
        RouteContext $context,
        ComponentRegistry $components,
    ): ResponseDraft {
        // A status nothing read is keyed at the shared unplaced status rather than at a number this tier
        // picks, so the preset that may also publish this error keys it the same way.
        $status = $exception->httpStatusHint === null
            ? FrameworkExceptionTable::UNPLACED_STATUS
            : (string) $exception->httpStatusHint;

        $draft = new ResponseDraft($status);
        // The contribution must carry the fallback producer (matching producer() above), or an
        // inference/integration/preset response for the same status would tie instead of winning.
        $contribution = Contribution::forProducer('fallback', $context->actionSource());

        $draft->setDescription(FrameworkExceptionTable::reason($status), $contribution);
        // Generic body, but not a generic error: the status still says which one, so the shared
        // component is named after it. A status with no reason phrase of its own declares nothing.
        $draft->claimComponentName(FrameworkExceptionTable::componentName($status), $contribution, isStatusDefault: true);
        $draft->content('application/json')->set('type', 'object', $contribution);
        $draft->content('application/json')->set('properties', ['message' => ['type' => 'string']], $contribution);

        return $draft;
    }
}
