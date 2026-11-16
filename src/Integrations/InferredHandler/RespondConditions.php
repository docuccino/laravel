<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Inference\ConditionTree;
use Docuccino\Core\Inference\TypeCondition;
use Docuccino\Laravel\Integrations\Support\RoutePredicates;

/**
 * Whether one return of a `respond()` callback can be reached for the route being documented, read off the
 * facts the engine proved there ({@see CallCondition}, {@see TypeCondition}) and the guards it read on the way
 * ({@see ConditionTree}). A fact not answerable without a request in hand, or about a response whose class
 * the build does not know ({@see RenderedResponse}), is no evidence either way, so the return stays reachable.
 */
final class RespondConditions
{
    /**
     * @param  list<CallCondition|TypeCondition>  $conditions  each proven to hold on the way to the return
     * @param  ConditionTree|null  $guards  what the guards on the way require where nothing proves each part
     */
    public static function reachable(array $conditions, RespondCallback $callback, RouteContext $context, ResponseDraft $rendered, ?RenderedResponse $sent = null, ?ConditionTree $guards = null): bool
    {
        $required = ConditionTree::all([
            ...array_map(ConditionTree::leaf(...), $conditions),
            ...($guards === null ? [] : [$guards]),
        ]);

        return $required->evaluate(static fn (CallCondition|TypeCondition $condition): ?bool => self::holds($condition, $callback, $context, $rendered, $sent)) !== false;
    }

    /** Whether the condition holds for this route, or null where that is not knowable. */
    private static function holds(CallCondition|TypeCondition $condition, RespondCallback $callback, RouteContext $context, ResponseDraft $rendered, ?RenderedResponse $sent): ?bool
    {
        $answer = $condition instanceof TypeCondition
            ? self::isA($condition, $callback, $sent)
            : self::answer($condition, $callback, $context, $rendered);

        // A call compared with a value of another type — a status read as a truth value — is not answered here.
        return $answer === null || get_debug_type($answer) !== get_debug_type($condition->value) ? null : $answer === $condition->value;
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
