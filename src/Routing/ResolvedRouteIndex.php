<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Illuminate\Routing\Route;

/**
 * An adapter-internal side-channel between {@see LaravelRouteResolver} — which reflects every route
 * while filtering — and {@see RouteContextBuilder}, which needs that same {@see Route} and its
 * reflection. The resolver records each route as it yields the descriptor; the builder reads it back
 * O(1) by descriptor, so a route is reflected once and never linearly re-located. This keeps
 * {@see RouteDescriptor} framework-agnostic (no Laravel Route on the descriptor). Bound scoped, so a
 * fresh index backs each request/build; a container miss simply degrades the builder to its own
 * lookup + reflection.
 *
 * @internal
 */
final class ResolvedRouteIndex
{
    /**
     * @var array<string, array{route: Route, reflected: ReflectedAction|null}>
     */
    private array $entries = [];

    public function put(RouteDescriptor $descriptor, Route $route, ?ReflectedAction $reflected): void
    {
        $this->entries[self::key($descriptor)] = ['route' => $route, 'reflected' => $reflected];
    }

    /**
     * @return array{route: Route, reflected: ReflectedAction|null}|null
     */
    public function get(RouteDescriptor $descriptor): ?array
    {
        return $this->entries[self::key($descriptor)] ?? null;
    }

    private static function key(RouteDescriptor $descriptor): string
    {
        return implode(',', $descriptor->methods)."\0".$descriptor->uri;
    }
}
