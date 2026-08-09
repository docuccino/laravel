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
 * The terminal {@see ExceptionToResponse} (design §6 chain): maps any signalled exception with a
 * status hint to a response carrying a generic `{message: string}` body, so error docs are never
 * empty when no more specific mapper matched. Pinned last so a specific mapper always wins first.
 * Reason phrases come from the shared {@see FrameworkExceptionTable} so this fallback can never drift
 * from the other tiers on a status's human label (e.g. 401 → "Unauthorized").
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
        $status = $exception->httpStatusHint ?? 500;

        $draft = new ResponseDraft((string) $status);
        // This is the terminal fallback tier: its contribution must carry the fallback producer/layer
        // (matching producer() above), so any inference/integration/preset response for the same
        // status correctly outranks it rather than tying at the inference layer.
        $contribution = Contribution::forProducer('fallback', $context->actionSource());

        $draft->setDescription(FrameworkExceptionTable::reason((string) $status), $contribution);
        $draft->content('application/json')->set('type', 'object', $contribution);
        $draft->content('application/json')->set('properties', ['message' => ['type' => 'string']], $contribution);

        return $draft;
    }
}
