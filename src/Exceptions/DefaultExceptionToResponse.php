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
use Docuccino\Laravel\Integrations\Support\AppRenderedErrors;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;

/**
 * The terminal {@see ExceptionToResponse}: any signalled exception gets a generic `{message: string}`
 * body, so error docs are never empty when nothing more specific matched. Pinned last so specific
 * mappers always win. Reason phrases come from the shared {@see FrameworkExceptionTable} so this tier
 * can't drift from the others on a status's label.
 *
 * The generic body is the FRAMEWORK's, so it is withheld on the one route where the application's own
 * handler demonstrably renders the exception and the build could not read the result — the status and
 * its reason phrase still answer ({@see AppRenderedErrors}).
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
        // A status nothing read is keyed at the exception's shared classification rather than at a number
        // this tier picks, so every tier that may also publish this error keys it the same way.
        $status = $exception->httpStatusHint === null
            ? FrameworkExceptionTable::classification($exception->exceptionFqcn)
            : (string) $exception->httpStatusHint;

        $draft = new ResponseDraft($status);
        // The contribution must carry the fallback producer (matching producer() above), or an
        // inference/integration response for the same status would tie instead of winning.
        $contribution = Contribution::forProducer('fallback', $context->actionSource());

        $draft->setDescription(FrameworkExceptionTable::reason($status), $contribution);

        // Same standing-aside as the framework-defaults tier makes, for the same reason and off the same
        // fact: the generic body below is what the FRAMEWORK sends, and an application whose own handler
        // demonstrably renders this exception has replaced it ({@see AppRenderedErrors}). Never being
        // empty is this tier's job; being empty is not what a status and a reason phrase are.
        if (AppRenderedErrors::includes($context, $exception->exceptionFqcn)) {
            return $draft;
        }

        // Generic body, but not a generic error: the status still says which one, so the shared
        // component is named after it. A status with no reason phrase of its own declares nothing.
        $draft->claimComponentName(FrameworkExceptionTable::componentName($status), $contribution, isStatusDefault: true);
        $draft->content('application/json')->set('type', 'object', $contribution);
        $draft->content('application/json')->set('properties', ['message' => ['type' => 'string']], $contribution);

        return $draft;
    }
}
