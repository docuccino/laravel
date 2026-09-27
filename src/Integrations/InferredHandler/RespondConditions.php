<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\CallCondition;
use Docuccino\Laravel\Integrations\Support\RoutePredicates;

/**
 * Whether one return of a `respond()` callback can be reached for the route being documented, read off the
 * facts the engine proved there ({@see CallCondition}). A call not answerable without a request in hand is
 * no evidence either way, so the return stays reachable.
 */
final class RespondConditions
{
    /**
     * @param  list<CallCondition>  $conditions
     */
    public static function reachable(array $conditions, RespondCallback $callback, RouteContext $context, ResponseDraft $rendered): bool
    {
        foreach ($conditions as $condition) {
            $answer = self::answer($condition, $callback, $context, $rendered);
            if ($answer !== null && $answer !== $condition->value) {
                return false;
            }
        }

        return true;
    }

    /**
     * What `$response->getStatusCode()` reads on the rendered response: its status where one was read, and
     * null for a stand-in key ({@see ResponseDraft::statusIsUnplaced()}) or a range.
     */
    public static function renderedStatus(ResponseDraft $rendered): ?int
    {
        return $rendered->statusIsUnplaced() || ! ctype_digit($rendered->status) ? null : (int) $rendered->status;
    }

    /** What the call answers for this route, or null where that is not knowable without a request. */
    private static function answer(CallCondition $condition, RespondCallback $callback, RouteContext $context, ResponseDraft $rendered): bool|int|null
    {
        if ($condition->parameter === $callback->requestParameter) {
            return match ($condition->method) {
                'is' => RoutePredicates::pathIs($condition->arguments, $context->route->uri),
                'routeIs' => RoutePredicates::routeIs($condition->arguments, $context->route->name),
                default => null,
            };
        }

        if ($condition->parameter === $callback->responseParameter
            && $condition->method === 'getStatusCode'
            && $condition->arguments === []
        ) {
            return self::renderedStatus($rendered);
        }

        return null;
    }
}
