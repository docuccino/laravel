<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Attributes\IgnoreResponse;
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
    /** Whether the route drops the response it would otherwise document at `$status`. */
    public static function drops(RouteContext $context, string|int $status): bool
    {
        foreach ($context->attributes->all(IgnoreResponse::class) as $ignore) {
            if ((string) $ignore->status === (string) $status) {
                return true;
            }
        }

        return false;
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
     * raised about a body nobody will see is not reported either.
     */
    public static function mapThrow(RouteContext $context, ThrownException $throw): ?MappedResponse
    {
        $snapshot = $context->components->snapshot();

        $mapped = $context->mapThrow($throw);
        if ($mapped === null || ! self::drops($context, $mapped->draft->status)) {
            return $mapped;
        }

        $context->components->restore($snapshot);

        return null;
    }
}
