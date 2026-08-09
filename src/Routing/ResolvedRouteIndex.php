<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Illuminate\Routing\Route;

/**
 * A side-channel from {@see LaravelRouteResolver}, which reflects every route while filtering, to
 * {@see RouteContextBuilder}, which needs the same {@see Route} and reflection. Keyed by descriptor so
 * the builder reads back O(1) and nothing gets reflected or re-located twice — and so
 * {@see RouteDescriptor} stays framework-agnostic. Bound scoped for a fresh index per build; a
 * container miss just degrades the builder to its own lookup.
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
