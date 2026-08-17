<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Illuminate\Http\Request;

/**
 * The shared page-size clamp a list endpoint runs its `per_page` through, taking the request as an
 * ARGUMENT — the shape `RequestPageSizeReader` has to follow a paginator's size argument into. Parsed as
 * test INPUT: its real source lines are what the reader's reflection correlation is proven against, so
 * moving `clamp()` within this file is fine but its body is data.
 */
final class ListPageSize
{
    public static function clamp(Request $request, int $default = 15, int $max = 100): int
    {
        return max(1, min($request->integer('per_page', $default), $max));
    }

    /** The negative twin: a size helper that reads nothing off the request, so it names no key. */
    public static function fixed(Request $request, int $default = 15): int
    {
        return max(1, $default);
    }

    /** Two keys in one body: which of them is the size is not decidable, so neither is claimed. */
    public static function ambiguous(Request $request): int
    {
        return max($request->integer('per_page', 15), $request->integer('limit', 20));
    }

    /** One key, two fallbacks: the key still holds, but no default can depend on which read came first. */
    public static function repeated(Request $request): int
    {
        return max($request->integer('per_page', 15), $request->integer('per_page', 20));
    }

    /** The read NAMED first, under a key of its own: the value still flows out of the return. */
    public static function limit(Request $request, int $max = 100): int
    {
        $limit = $request->integer('limit', 15);

        return min($limit, $max);
    }

    /** A preset selector: the key picks the arm, and every arm's value is a literal of this body's own. */
    public static function preset(Request $request): int
    {
        return match ($request->input('preset')) {
            'small' => 10,
            default => 25,
        };
    }

    /** A read the returned value never touches, because it lives in a closure this body never calls. */
    public static function lazy(Request $request): int
    {
        $threshold = function () use ($request): int {
            return $request->integer('threshold', 5);
        };

        return 20;
    }
}
