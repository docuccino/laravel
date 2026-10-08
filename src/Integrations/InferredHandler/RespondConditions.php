<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Inference\TypeCondition;
use Docuccino\Laravel\Integrations\Support\RoutePredicates;

/**
 * Whether one return of a `respond()` callback can be reached for the route being documented, read off the
 * facts the engine proved there ({@see CallCondition}, {@see TypeCondition}). A fact not answerable without
 * a request in hand, or about a response whose class the build does not know ({@see RenderedResponse}), is
 * no evidence either way, so the return stays reachable.
 */
final class RespondConditions
{
    /**
     * @param  list<CallCondition|TypeCondition>  $conditions
     */
    public static function reachable(array $conditions, RespondCallback $callback, RouteContext $context, ResponseDraft $rendered, ?RenderedResponse $sent = null): bool
    {
        foreach ($conditions as $condition) {
            $answer = $condition instanceof TypeCondition
                ? self::isA($condition, $callback, $sent)
                : self::answer($condition, $callback, $context, $rendered);
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

    /** What the test answers for the rendered response, or null for any other parameter, or a class unknown. */
    private static function isA(TypeCondition $condition, RespondCallback $callback, ?RenderedResponse $sent): ?bool
    {
        return $condition->parameter === $callback->responseParameter ? $sent?->isA($condition->class) : null;
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
