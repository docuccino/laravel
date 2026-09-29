<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Identity\IdentityGenerator;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;

/**
 * Which route answers each URL form (`/posts` of `/posts/{page?}`), per method in the order the
 * installed router tries routes — its own `get($method)`, which puts domain routes first where the
 * version does — against what each compiles to ({@see RouteCoverage}). A form an earlier one provably
 * answers for is never reached, one that cannot be decided is kept. Where reachable forms would publish
 * one path, a route's own path beats another's short form, and between short forms the one first in
 * the document's own order — path template, then host — is published, so which one never follows
 * registration. Routes are told apart by what they are ({@see key()}), never by object: a cached
 * router builds a new object each time it is asked for one.
 *
 * @phpstan-import-type Token from RouteTemplate
 *
 * @phpstan-type Form array{uri: string, omitted: list<string>, methods: list<string>}
 * @phpstan-type Shadow array{by: string, replaced: bool, reached: list<string>}
 * @phpstan-type Contest array{omitted: list<string>, method: string, path: string, by: string}
 * @phpstan-type Contender array{id: string, form: int, short: bool, order: string, signature: string}
 *
 * @internal
 */
final class ServedForms
{
    /** @var array<string, Route> */
    private array $routes = [];

    /** @var array<string, list<Form>> */
    private array $forms = [];

    /** @var array<string, array<string, Shadow>> method → what answers every URL the route's own form does */
    private array $shadowed = [];

    /** @var array<string, list<Contest>> */
    private array $contested = [];

    public function __construct(RouteCollectionInterface $routes, private readonly IdentityGenerator $identity = new IdentityGenerator)
    {
        /** @var array<string, true> $methods */
        $methods = [];

        /** @var iterable<Route> $all */
        $all = $routes->getRoutes();
        foreach ($all as $route) {
            // A fallback is matched after every other route, so it answers nothing another could.
            if ($route->isFallback) {
                continue;
            }

            $id = self::key($route);
            $this->routes[$id] = $route;
            $this->forms[$id] = array_map(
                static fn (array $form): array => ['uri' => $form['uri'], 'omitted' => $form['omitted'], 'methods' => []],
                RouteTemplate::forms($route->uri()),
            );
            foreach (self::methods($route) as $method) {
                $methods[$method] = true;
            }
        }

        foreach (array_keys($methods) as $method) {
            $this->serve($routes, $method);
        }

        // Each form lists its methods in the route's own order, whatever order they were settled in.
        foreach ($this->forms as $id => $forms) {
            $this->forms[$id] = array_map(
                fn (array $form): array => ['uri' => $form['uri'], 'omitted' => $form['omitted'], 'methods' => array_values(array_intersect(self::methods($this->routes[$id]), $form['methods']))],
                $forms,
            );
        }
    }

    /**
     * What tells one route of a collection from another, the same whichever object stands for it: the
     * collection's own key (methods, host and URI) and the action.
     */
    public static function key(Route $route): string
    {
        return implode('|', self::methods($route))."\0".$route->getDomain()."\0".$route->uri()."\0".$route->getActionName();
    }

    /**
     * The route's forms, full form first, each with the methods it serves.
     *
     * @return list<Form>
     */
    public function of(Route $route): array
    {
        return $this->forms[self::key($route)] ?? [['uri' => $route->uri(), 'omitted' => [], 'methods' => self::methods($route)]];
    }

    /**
     * The methods no request reaches the route's full form by, each with the route answering instead
     * (host and URI), whether that one replaced it (one method and URL registered twice), and the paths
     * of the shorter forms a request still reaches it by.
     *
     * @return array<string, Shadow>
     */
    public function shadowed(Route $route): array
    {
        return $this->shadowed[self::key($route)] ?? [];
    }

    /**
     * The route's short forms the router serves but the document cannot, because another route's form
     * is the same path and method.
     *
     * @return list<Contest>
     */
    public function contested(Route $route): array
    {
        return $this->contested[self::key($route)] ?? [];
    }

    private function serve(RouteCollectionInterface $routes, string $method): void
    {
        /** @var array<string, Route> $ordered */
        $ordered = $routes->get($method);

        // The collection keeps one route per method and URL, so a later registration replaced any other.
        foreach ($this->routes as $id => $route) {
            $holder = $ordered[$route->getDomain().$route->uri()] ?? null;
            if ($holder !== null && self::key($holder) !== $id && in_array($method, self::methods($route), true)) {
                $this->shadowed[$id][$method] = ['by' => self::template($holder), 'replaced' => true, 'reached' => []];
            }
        }

        /** @var array<string, list<array{string, Route, list<Token>}>> $seen skeleton → the forms tried so far */
        $seen = [];
        /** @var array<string, list<array{int, Route, string, string}>> $tried first path segment → the routes tried so far */
        $tried = [];
        /** @var array<string, list<Contender>> $slots */
        $slots = [];
        /** @var array<string, list<string>> $reached route → the paths of its short forms a request reaches */
        $reached = [];

        foreach (array_values($ordered) as $position => $route) {
            $id = self::key($route);
            if (! isset($this->routes[$id])) {
                continue;
            }

            foreach ($this->forms[$id] as $index => $form) {
                $tokens = RouteTemplate::tokens($route, $form['omitted']);
                $short = $form['omitted'] !== [];

                if ($tokens !== null) {
                    $by = self::coveredBy($seen, $id, $route, $tokens) ?? self::pathCoveredBy($tried, $route, $tokens);
                    $seen[RouteCoverage::skeleton($tokens)][] = [$id, $route, $tokens];

                    if ($by !== null) {
                        if (! $short) {
                            $this->shadowed[$id][$method] = ['by' => self::template($by), 'replaced' => false, 'reached' => []];
                        }

                        continue;
                    }
                }

                $path = OasPath::of($form['uri']);
                if ($short) {
                    $reached[$id][] = $path;
                }

                // Paths that differ only by parameter names are one path to OpenAPI, whatever the host.
                $slots[$this->identity->normalizePathTemplate($path)][] = [
                    'id' => $id,
                    'form' => $index,
                    'short' => $short,
                    'order' => '/'.ltrim($form['uri'], '/')."\0".(RouteHost::of($route) ?? '')."\0".$id,
                    'signature' => self::signature($route, $method),
                ];
            }

            $compiled = RouteTemplate::pathRegex($route);
            if ($compiled !== null) {
                $tried[self::firstSegment(RouteTemplate::tokens($route) ?? [])][] = [$position, $route, $compiled['regex'], $compiled['prefix']];
            }
        }

        foreach ($slots as $contenders) {
            $this->settle($contenders, $method);
        }

        foreach ($reached as $id => $paths) {
            if (isset($this->shadowed[$id][$method]) && ! $this->shadowed[$id][$method]['replaced']) {
                $this->shadowed[$id][$method]['reached'] = $paths;
            }
        }
    }

    /**
     * Every reachable form publishing one path and method: each route's own path goes through, the
     * document's collision to report between two of them, and a short form only where no own path
     * holds the slot and it is the first of the short forms in the document's order.
     *
     * @param  list<Contender>  $contenders
     */
    private function settle(array $contenders, string $method): void
    {
        $own = array_values(array_filter($contenders, static fn (array $contender): bool => ! $contender['short']));
        $pool = $own === [] ? $contenders : $own;
        usort($pool, static fn (array $a, array $b): int => strcmp($a['order'], $b['order']));
        $winner = $pool[0];

        foreach ($contenders as $contender) {
            if (! $contender['short'] || $contender === $winner) {
                $this->forms[$contender['id']][$contender['form']]['methods'][] = $method;

                continue;
            }

            $form = $this->forms[$contender['id']][$contender['form']];
            $this->contested[$contender['id']][] = ['omitted' => $form['omitted'], 'method' => $method, 'path' => OasPath::of($form['uri']), 'by' => $winner['signature']];
        }
    }

    /**
     * The first route tried whose path regex matches the one path a form without segments carries,
     * where one provably does.
     *
     * @param  array<string, list<array{int, Route, string, string}>>  $tried
     * @param  list<Token>  $tokens
     */
    private static function pathCoveredBy(array $tried, Route $route, array $tokens): ?Route
    {
        foreach ($tokens as $token) {
            if ($token['name'] !== null) {
                return null;
            }
        }

        $path = RouteCoverage::literalPath($tokens);
        $key = preg_match('{\A(/[^/]+)}', $path, $segment) === 1 ? $segment[1] : '';

        $first = null;
        foreach ([...($tried[$key] ?? []), ...($key === '' ? [] : $tried[''] ?? [])] as [$position, $earlier, $regex, $prefix]) {
            if (($first === null || $position < $first[0]) && str_starts_with($path, $prefix) && RouteCoverage::coversPath($earlier, $regex, $route, $tokens)) {
                $first = [$position, $earlier];
            }
        }

        return $first[1] ?? null;
    }

    /**
     * The earlier route whose form answers every request this form matches, where one provably does.
     *
     * @param  array<string, list<array{string, Route, list<Token>}>>  $seen
     * @param  list<Token>  $tokens
     */
    private static function coveredBy(array $seen, string $id, Route $route, array $tokens): ?Route
    {
        foreach ($seen[RouteCoverage::skeleton($tokens)] ?? [] as [$earlierId, $earlier, $earlierTokens]) {
            if ($earlierId !== $id && RouteCoverage::covers($earlier, $earlierTokens, $route, $tokens)) {
                return $earlier;
            }
        }

        return null;
    }

    /**
     * The first segment of every path the route matches, where its literal text settles one (`/users` of
     * `users/{user}`); an empty key, tried for every path, where it does not.
     *
     * @param  list<Token>  $tokens
     */
    private static function firstSegment(array $tokens): string
    {
        $first = $tokens[0] ?? null;
        if ($first === null || $first['name'] !== null) {
            return '';
        }

        $text = $first['text'];

        if (preg_match('{\A(/[^/]+)/}', $text, $segment) === 1) {
            return $segment[1];
        }

        $next = $tokens[1] ?? null;

        return preg_match('{\A/[^/]+\z}', $text) === 1 && ($next === null || $next['separator'] === '/' || str_starts_with($next['text'], '/')) ? $text : '';
    }

    /** @return list<string> */
    private static function methods(Route $route): array
    {
        return array_values(array_filter($route->methods(), 'is_string'));
    }

    private static function signature(Route $route, string $method): string
    {
        return strtoupper($method).' '.self::template($route);
    }

    /** The route as its author wrote it: host and URI, optional markers and all. */
    private static function template(Route $route): string
    {
        return (RouteHost::of($route) ?? '').'/'.ltrim($route->uri(), '/');
    }
}
