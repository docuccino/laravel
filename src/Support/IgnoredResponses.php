<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\MappedResponse;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\ThrownException;

/**
 * The one reading of `#[IgnoreResponse]`, asked by every producer that writes a response.
 *
 * A response is not a parameter: it carries a BODY, and a body hoists into `components.schemas` and
 * `components.responses` the moment it is converted. Nothing prunes either bucket by reachability, so
 * removing the response afterwards — the way `#[IgnoreParam]` removes a parameter in Finalize — trades
 * a visible defect for an invisible one: the status goes and the components it pulled in stay, reachable
 * from nothing, published to everyone. So each producer CONSULTS this before it converts anything, and
 * declining to build the response is also declining to hoist its body.
 *
 * A declaration drops exactly the status it names and no other. It has no positive form, so a
 * method-level ignore never contests a class-level one — both apply, and the set is the union. It cannot
 * name a range key either (`3XX`, `4XX`), which is the answer to both directions of the range question:
 * an ignore establishes nothing, so it neither retires the range a member sits in nor narrows one.
 */
final class IgnoredResponses
{
    /**
     * The route-local record of which statuses an ignore really took effect on, written by every
     * consultation below and read once the route's build is over
     * (`UnmatchedIgnoredResponsesExtension`).
     *
     * Keyed by status rather than by declaration, which is what a declaration IS here: two that name one
     * status — a controller's and its action's — both did their job, and one record answers for both.
     *
     * A declaration is consulted PER PRODUCER, as each response is about to be written, so a status no
     * producer would ever write is simply never asked about and there is nowhere in this class that
     * could know the declaration went unused. The record is what makes the end of the route's build such
     * a place. `RouteNotes` is where it lives because it is the one per-route mutable bag, and
     * because it snapshots and restores — which the rollback below needs anyway.
     */
    public const string MATCHED_CHANNEL = 'attribute.ignore-response-matched';

    /** The single key under {@see MATCHED_CHANNEL}; the statuses are the values. */
    public const string MATCHED_KEY = 'status';

    /** Whether the route drops the response it would otherwise document at `$status`. */
    public static function drops(RouteContext $context, string|int $status): bool
    {
        foreach ($context->attributes->all(IgnoreResponse::class) as $ignore) {
            if ((string) $ignore->status === (string) $status) {
                self::recordMatch($context, $status);

                return true;
            }
        }

        return false;
    }

    /**
     * The BACKSTOP: remove every response the route drops that something has ALREADY written, and
     * record each removal as the match it is.
     *
     * Every built-in producer consults {@see drops()} before it converts anything, so what is left for
     * this to find is a producer this package does not own — a third-party extension in an earlier
     * phase. The `hasResponse()` guard is what makes the removal a match rather than a guess: a status
     * standing in the draft is one something really wrote, and crediting the declaration for it is the
     * difference between an author being told nothing and being told to delete a declaration that is
     * the only reason the response is gone.
     */
    public static function sweep(OperationDraft $operation, RouteContext $context): void
    {
        foreach ($context->attributes->all(IgnoreResponse::class) as $ignore) {
            $status = (string) $ignore->status;
            if (! $operation->hasResponse($status)) {
                continue;
            }

            self::recordMatch($context, $status);
            $operation->removeResponse($status);
        }
    }

    /** {@see MATCHED_CHANNEL}. */
    private static function recordMatch(RouteContext $context, string|int $status): void
    {
        $context->notes()->record(self::MATCHED_CHANNEL, self::MATCHED_KEY, (string) $status);
    }

    /**
     * {@see RouteContext::mapThrow()} for a producer that must not publish a dropped status: null when
     * the route drops the status the mapped response landed on, with everything the mapping registered
     * rolled back.
     *
     * The status is read off the MAPPED draft rather than off the throw, because a mapper answers at the
     * status its own tier proves — a table entry for the exception class, a code folded out of a
     * `render()` — which is not always the one the analysis attached to the throw. Deciding beforehand on
     * the throw's status would drop a response the author never named whenever the two differ.
     *
     * Mapping is therefore a READ here, and a read leaves nothing behind: the registry rolls back to
     * where it stood, so a body converted only to be discarded hoists no component, and a diagnostic
     * raised about a body nobody will see is not reported either. A mapper's ROUTE NOTES roll back with
     * it — those are the same kind of fact reaching the document by another road, and a summary asking
     * the author to make a response foldable that they asked by name to be dropped fires exactly where
     * nothing can be done.
     *
     * What deliberately does NOT roll back is the route's DEPENDENCY FILES. A mapper read those to reach
     * its answer, so the answer — including "and it was dropped" — is a function of them, and a fragment
     * that did not re-hash them would serve a stale decision after the file that drove it changed.
     * Over-keying costs a rebuild; under-keying costs correctness.
     */
    public static function mapThrow(RouteContext $context, ThrownException $throw): ?MappedResponse
    {
        $components = $context->components->snapshot();
        $notes = $context->notes()->snapshot();

        $mapped = $context->mapThrow($throw);
        if ($mapped === null || ! self::drops($context, $mapped->draft->status)) {
            return $mapped;
        }

        $context->components->restore($components);
        $context->notes()->restore($notes);

        // The rollback took the match `drops()` just recorded with it. The body is rightly gone, but
        // that the declaration DROPPED something is a fact about the declaration rather than about the
        // response nobody will see, so it is recorded again on the far side — otherwise the one path
        // where an ignore does the most work is the one that reads as having done none.
        self::recordMatch($context, $mapped->draft->status);

        return null;
    }
}
