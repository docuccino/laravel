<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Laravel\Support\IgnoredResponses;
use Docuccino\Laravel\Support\UnmatchedDeclaration;

/**
 * Reports an `#[IgnoreResponse]` that dropped nothing.
 *
 * `#[IgnoreResponse]` is consulted per producer, as each response is about to be written
 * ({@see IgnoredResponses}), so a status no producer would ever write is never asked about and no
 * consultation can tell that a declaration went unused. This is the first point that can: `Finalize` at
 * `LAST`, once every producer that could have asked has run, reading the route-local record of what the
 * consultations actually dropped. A pass of its own rather than a branch inside the reader, because the
 * reader answers a question and this one asks what was never asked.
 *
 * It publishes nothing — the document is already whatever the producers made it, and an ignore that
 * dropped nothing subtracted nothing to put back.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class UnmatchedIgnoredResponsesExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $direct = $context->attributes->direct(IgnoreResponse::class);

        if ($direct === []) {
            return;
        }

        $matched = $context->notes()->all()[IgnoredResponses::MATCHED_CHANNEL][IgnoredResponses::MATCHED_KEY] ?? [];
        $published = $operation->responseStatuses();

        // Deduped: two identical declarations on one action are one mistake, and saying it twice would
        // make the reader look for a second one.
        $reported = [];

        foreach ($direct as $ignore) {
            if (in_array((string) $ignore->status, $matched, true) || in_array($ignore->status, $reported, true)) {
                continue;
            }

            $reported[] = $ignore->status;

            $context->components->addDiagnostic(UnmatchedDeclaration::response(
                $ignore->status,
                $published,
                $context->actionSource(),
                $context->route->signature(),
            ));
        }
    }
}
