<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;

/**
 * The folded arguments of one call, for the readers that index a paginator terminal's signature. Placement
 * is {@see ArgumentSlots}: a positional argument lands under its 0-based index, a named one under its
 * parameter name, and a spread the call site wrote out is expanded into the positions it really takes.
 * Each slot holds a scalar or null where it was written but would not fold — so `array_key_exists` is what
 * separates "absent" from "written and unresolvable".
 *
 * A spread this build cannot read has no position at all: it fills its index and every later one from a
 * sequence, so a position that looks absent may well be supplied, and a name may be bound in there too.
 * Nothing about such a call is indexable, and the whole answer is null rather than an array whose gaps
 * read as defaults the call never took.
 */
final class FoldedArguments
{
    /** @return array<array-key, string|int|float|bool|null>|null */
    public static function of(Node\Expr\MethodCall|Node\Expr\StaticCall $call, TypeScope $scope): ?array
    {
        if ($call->isFirstClassCallable()) {
            return null; // a callable, not a call: it passes no arguments at all
        }

        $slots = ArgumentSlots::of($call->getArgs());
        if ($slots->isOpaque()) {
            return null;
        }

        $args = [];
        foreach ($slots->all() as $key => $expr) {
            $value = $scope->constantValueOf($expr);
            $args[$key] = $value !== null && $value->isScalar() ? $value->scalar : null;
        }

        return $args;
    }
}
