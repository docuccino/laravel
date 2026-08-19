<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Support\Hydrate;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Finds the operation a reader means from one thing they typed. Nobody runs `docuccino:explain` cold —
 * they run it having just read `POST /api/invoices` off the viewer or the exported artifact — so
 * method + path is the primary spelling, and a route name is never required: the routes that document
 * worst are disproportionately the unnamed and closure ones.
 *
 * Tried in order, and the first spelling that matches anything wins: an exact route name, an exact
 * operation id, then the path — with or without a leading method, with or without a leading slash, and
 * with a configured server's base path added or taken away. Nothing matches exactly, and it falls back
 * to a case-insensitive substring over all three, which turns a failed lookup into a menu.
 *
 * Several matches is an ANSWER, not a failure: the caller lists them rather than picking one.
 *
 * @internal
 */
final readonly class OperationLookup
{
    public function __construct(private Router $router) {}

    /**
     * Every operation one built document publishes, sorted by path then method, with whatever the
     * router still knows about the route behind each.
     *
     * @param  array<string, mixed>  $document
     * @return list<OperationMatch>
     */
    public function operations(string $key, array $document): array
    {
        $routes = $this->routeIndex();
        $paths = Hydrate::map($document['paths'] ?? null);

        $operations = [];
        foreach ($paths as $path => $item) {
            $path = (string) $path;

            foreach (Hydrate::map($item) as $method => $operation) {
                $method = strtolower((string) $method);
                if (! in_array($method, PathItem::METHODS, true) || ! is_array($operation)) {
                    continue;
                }

                $known = $routes[$method.' '.$path] ?? ['name' => [], 'action' => []];

                $operations[] = new OperationMatch(
                    document: $key,
                    path: $path,
                    method: $method,
                    operationId: Hydrate::stringOrNull($operation['operationId'] ?? null),
                    name: self::only($known['name']),
                    action: self::only($known['action']),
                );
            }
        }

        usort($operations, static fn (OperationMatch $a, OperationMatch $b): int => [$a->path, $a->method, $a->document] <=> [$b->path, $b->method, $b->document]);

        return $operations;
    }

    /**
     * The operations one query names. Empty when nothing matches; more than one when the query is
     * genuinely ambiguous.
     *
     * @param  list<OperationMatch>  $operations
     * @param  string|null  $method  the `--method` filter, which only ever narrows
     * @return list<OperationMatch>
     */
    public function match(array $operations, string $query, ?string $method = null): array
    {
        $query = trim($query);
        [$inlineMethod, $uri] = self::splitMethod($query);

        foreach ([$method, $inlineMethod] as $filter) {
            if ($filter !== null) {
                $wanted = strtolower($filter);
                $operations = array_values(array_filter($operations, static fn (OperationMatch $o): bool => $o->method === $wanted));
            }
        }

        $tiers = [
            static fn (OperationMatch $o): bool => $o->name === $query,
            static fn (OperationMatch $o): bool => $o->operationId === $query,
            self::pathMatcher($uri, $operations),
            self::substringMatcher($uri === '' ? $query : $uri),
        ];

        foreach ($tiers as $matches) {
            $found = array_values(array_filter($operations, $matches));

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * @param  list<OperationMatch>  $operations
     * @return callable(OperationMatch): bool
     */
    private static function pathMatcher(string $uri, array $operations): callable
    {
        if ($uri === '') {
            return static fn (OperationMatch $o): bool => false;
        }

        $paths = array_map(static fn (OperationMatch $o): string => $o->path, $operations);
        $candidates = self::spellings($uri, $paths);

        return static fn (OperationMatch $o): bool => in_array($o->path, $candidates, true);
    }

    /**
     * Every path the query could be spelling: as typed (leading slash normalised), and with each
     * prefix the published paths share added or taken away.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function spellings(string $uri, array $paths): array
    {
        $normalised = '/'.ltrim($uri, '/');
        $candidates = [$normalised];

        foreach (self::prefixes($paths) as $prefix) {
            $candidates[] = $prefix.$normalised;

            if (str_starts_with($normalised, $prefix.'/')) {
                $candidates[] = substr($normalised, strlen($prefix));
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * The leading segments the document's own paths use, longest first — `/api`, `/api/v2`. Read off
     * the paths rather than off `servers`, because either half can be the one carrying the prefix.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function prefixes(array $paths): array
    {
        $prefixes = [];

        foreach ($paths as $path) {
            $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
            $prefix = '';

            foreach (array_slice($segments, 0, 2) as $segment) {
                if (str_contains($segment, '{')) {
                    break;
                }

                $prefix .= '/'.$segment;
                if (! in_array($prefix, $prefixes, true)) {
                    $prefixes[] = $prefix;
                }
            }
        }

        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $prefixes;
    }

    /**
     * @return callable(OperationMatch): bool
     */
    private static function substringMatcher(string $needle): callable
    {
        $needle = mb_strtolower(ltrim($needle, '/'));

        if ($needle === '') {
            return static fn (OperationMatch $o): bool => false;
        }

        return static function (OperationMatch $o) use ($needle): bool {
            foreach ([$o->path, $o->name ?? '', $o->operationId ?? ''] as $haystack) {
                if (str_contains(mb_strtolower($haystack), $needle)) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * A leading HTTP method, when the query has one. A route name never carries a space, so the split
     * only ever fires on a `POST /api/invoices`-shaped query.
     *
     * @return array{0: string|null, 1: string}
     */
    private static function splitMethod(string $query): array
    {
        $parts = preg_split('/\s+/', $query, 2) ?: [];
        $head = strtolower($parts[0] ?? '');

        if (isset($parts[1]) && $parts[1] !== '' && in_array($head, PathItem::METHODS, true)) {
            return [$head, trim($parts[1])];
        }

        return [null, $query];
    }

    /**
     * The router's own view of what answers each method + path, as `method /path` =>
     * `{name: list, action: list}`. Deduped and sorted, so nothing here depends on registration order.
     *
     * @return array<string, array{name: list<string>, action: list<string>}>
     */
    private function routeIndex(): array
    {
        $index = [];

        /** @var iterable<Route> $routes */
        $routes = $this->router->getRoutes();

        foreach ($routes as $route) {
            $path = OasPath::of($route->uri());

            foreach ($route->methods() as $method) {
                if (! is_string($method)) {
                    continue;
                }

                $key = strtolower($method).' '.$path;
                $index[$key] ??= ['name' => [], 'action' => []];

                $name = $route->getName();
                if ($name !== null && $name !== '' && ! in_array($name, $index[$key]['name'], true)) {
                    $index[$key]['name'][] = $name;
                }

                $action = $route->getActionName();
                if ($action !== '' && ! in_array($action, $index[$key]['action'], true)) {
                    $index[$key]['action'][] = $action;
                }
            }
        }

        foreach ($index as $key => $entry) {
            sort($entry['name']);
            sort($entry['action']);
            $index[$key] = $entry;
        }

        return $index;
    }

    /**
     * The one value, or none — two routes answering one method and path (they differ by host) agree
     * on nothing this can print, and naming one of them would be a guess.
     *
     * @param  list<string>  $values
     */
    private static function only(array $values): ?string
    {
        return count($values) === 1 ? $values[0] : null;
    }
}
