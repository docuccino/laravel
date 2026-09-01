<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Laravel\Integrations\Support\FoldedArguments;
use Docuccino\Laravel\Support\FrameworkClasses;
use Illuminate\Support\Str;
use PhpParser\Node;

/**
 * Reads a `calculateResponseStatus()` override that picks between two constant statuses on the route's
 * NAME — `return $request->routeIs('*.store') ? 201 : 200;` — so the status can be narrowed to the one
 * THIS route takes. Both arms folded and published is false per route: the same body appears under 201
 * on a GET the server can only ever answer 200 with.
 *
 * The boundary is deliberately narrow, and it is two rules rather than one. The outer rule is what may
 * be folded at all: facts the route descriptor already carries. A predicate over runtime state
 * the build does not have — the authenticated user, config, the environment, the request body — is
 * outside it and must never be folded; the honest answer there is the union. The inner rule is what
 * has been MEASURED: one `return` of a ternary whose condition is `routeIs()` / `route()->named()`
 * over constant patterns. `isMethod()`/`method()`, `is()` on the URI, middleware predicates and the
 * `if`-guard-clause form are all decidable from the descriptor and still left out, because nothing has
 * counted them in the wild and a mechanism sized to what could be true is a maintenance cost with no
 * defect behind it. Anything unrecognised leaves the union exactly as it was.
 *
 * Matching is Laravel's own `Str::is`, and a route with no name matches nothing — the two halves of
 * how `Illuminate\Routing\Route::named()` decides it at runtime.
 *
 * @phpstan-type RouteNameDecision array{patterns: list<string>, negated: bool, whenTrue: int, whenFalse: int}
 */
final class RouteConditionalStatus implements TraceVisitor
{
    /**
     * Every `return` in the method, keyed by where it sits — the walk may hand one node over more than
     * once, and two DIFFERENT returns are what disqualifies the whole shape.
     *
     * @var array<string, true>
     */
    private array $returns = [];

    /** @var RouteNameDecision|null */
    private ?array $decision = null;

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if (! $node instanceof Node\Stmt\Return_) {
            return false; // nothing here descends: the override's own body is the whole subject
        }

        $this->returns[$node->getStartFilePos().':'.$node->getStartLine()] = true;

        if ($node->expr instanceof Node\Expr\Ternary) {
            $this->decision ??= $this->readTernary($node->expr, $scope);
        }

        return false;
    }

    /**
     * The status this route takes, or null when the override was not a route-name decision — which
     * includes a body with more than one `return`, whatever the one this saw looked like.
     */
    public function statusFor(?string $routeName): ?int
    {
        if (count($this->returns) !== 1 || $this->decision === null) {
            return null;
        }

        $matches = $routeName !== null && Str::is($this->decision['patterns'], $routeName);
        $holds = $this->decision['negated'] ? ! $matches : $matches;

        return $holds ? $this->decision['whenTrue'] : $this->decision['whenFalse'];
    }

    /**
     * `<route-name predicate> ? <int> : <int>`, optionally negated once. Null for every other shape,
     * a short `?:` included — it has no second arm to fold.
     *
     * @return RouteNameDecision|null
     */
    private function readTernary(Node\Expr\Ternary $ternary, TypeScope $scope): ?array
    {
        if ($ternary->if === null) {
            return null;
        }

        $whenTrue = $this->intValue($ternary->if, $scope);
        $whenFalse = $this->intValue($ternary->else, $scope);
        if ($whenTrue === null || $whenFalse === null) {
            return null;
        }

        $condition = $ternary->cond;
        $negated = false;
        if ($condition instanceof Node\Expr\BooleanNot) {
            $negated = true;
            $condition = $condition->expr;
        }

        $patterns = $this->patternsOf($condition, $scope);

        return $patterns === null
            ? null
            : ['patterns' => $patterns, 'negated' => $negated, 'whenTrue' => $whenTrue, 'whenFalse' => $whenFalse];
    }

    /**
     * The route-name patterns a predicate tests, or null when the expression is not one. Two spellings
     * of the same runtime call: `$request->routeIs(…)` is `$request->route()->named(…)`.
     *
     * @return list<string>|null
     */
    private function patternsOf(Node\Expr $expr, TypeScope $scope): ?array
    {
        if (! $expr instanceof Node\Expr\MethodCall || ! $expr->name instanceof Node\Identifier) {
            return null;
        }

        $recognised = match ($expr->name->toString()) {
            'routeIs' => FrameworkClasses::isRequest($expr->var, $scope),
            'named' => $this->isRouteOfRequest($expr->var, $scope),
            default => false,
        };

        return $recognised ? $this->constantStrings($expr, $scope) : null;
    }

    /** Whether an expression is `<request>->route()` — the argument-less accessor, not a parameter read. */
    private function isRouteOfRequest(Node\Expr $expr, TypeScope $scope): bool
    {
        return $expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'route'
            && ! $expr->isFirstClassCallable()
            && $expr->getArgs() === []
            && FrameworkClasses::isRequest($expr->var, $scope);
    }

    /**
     * The call's arguments as a plain list of constant strings, or null for anything else. Placement is
     * {@see FoldedArguments}: a named argument lands under its name and a spread the call site did not
     * write out makes the whole call unreadable, and neither is a list — which is the answer this needs,
     * because a pattern list read short narrows to a status the server may well still send.
     *
     * @return list<string>|null
     */
    private function constantStrings(Node\Expr\MethodCall $call, TypeScope $scope): ?array
    {
        $args = FoldedArguments::of($call, $scope);
        if ($args === null || $args === [] || ! array_is_list($args)) {
            return null;
        }

        $patterns = [];
        foreach ($args as $value) {
            if (! is_string($value)) {
                return null;
            }

            $patterns[] = $value;
        }

        return $patterns;
    }

    /** An expression's folded int value, or null when it isn't a constant int. */
    private function intValue(Node\Expr $expr, TypeScope $scope): ?int
    {
        $value = $scope->constantValueOf($expr);

        return $value !== null && $value->isScalar() && is_int($value->scalar) ? $value->scalar : null;
    }
}
