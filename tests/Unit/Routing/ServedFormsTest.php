<?php

declare(strict_types=1);

use Docuccino\Laravel\Routing\ServedForms;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\RouteCollectionInterface;

/**
 * Which route serves each URL form, as the router matches: per method, in the order the installed
 * router tries routes, the first whose compiled path, host and scheme accept the request. The route
 * collection itself is the oracle for every row — a URL is asked of it, never of this reading.
 */
beforeEach(function (): void {
    $this->route = static fn (array $methods, string $uri, string $answer): Route => new Route($methods, $uri, static fn (): string => $answer);
    $this->collect = static function (Route ...$routes): RouteCollection {
        $collection = new RouteCollection;
        foreach ($routes as $route) {
            $collection->add($route);
        }

        return $collection;
    };
});

it('gives a short form\'s URL to the route tried first, as the router does', function (bool $explicitFirst): void {
    $optional = ($this->route)(['GET', 'HEAD'], 'posts/{page?}', 'optional');
    $explicit = ($this->route)(['GET', 'HEAD'], 'posts', 'explicit');
    $collection = $explicitFirst ? ($this->collect)($explicit, $optional) : ($this->collect)($optional, $explicit);

    $served = new ServedForms($collection);

    expect($collection->match(Request::create('/posts')))->toBe($explicitFirst ? $explicit : $optional)
        ->and($served->of($optional)[1]['methods'])->toBe($explicitFirst ? [] : ['GET', 'HEAD'])
        ->and($served->of($explicit)[0]['methods'])->toBe($explicitFirst ? ['GET', 'HEAD'] : [])
        ->and($served->shadowed($explicit))->toBe($explicitFirst ? [] : [
            'GET' => ['by' => '/posts/{page?}', 'replaced' => false, 'reached' => []],
            'HEAD' => ['by' => '/posts/{page?}', 'replaced' => false, 'reached' => []],
        ]);
})->with(['explicit first' => [true], 'explicit after' => [false]]);

it('shares a URL out per method', function (): void {
    $optional = ($this->route)(['GET', 'HEAD', 'POST'], 'posts/{page?}', 'optional');
    $explicit = ($this->route)(['POST'], 'posts', 'explicit');

    $served = new ServedForms(($this->collect)($explicit, $optional));

    expect($served->of($optional)[1]['methods'])->toBe(['GET', 'HEAD'])
        ->and($served->of($optional)[0]['methods'])->toBe(['GET', 'HEAD', 'POST']);
});

it('reports a route another answers every URL of, and publishes only the one serving them', function (string $firstUri, string $secondUri, string $url): void {
    $first = ($this->route)(['GET'], $firstUri, 'first');
    $second = ($this->route)(['GET'], $secondUri, 'second');
    $collection = ($this->collect)($first, $second);

    $served = new ServedForms($collection);

    expect($collection->match(Request::create($url)))->toBe($first)
        ->and($served->of($second)[0]['methods'])->toBe([])
        ->and($served->shadowed($second)['GET'] ?? null)->toBe(['by' => '/'.$firstUri, 'replaced' => false, 'reached' => []]);
})->with([
    // Differing only by names, the two are one path to OpenAPI as well as one to the router.
    'a short form and a renamed full route' => ['users/{id}/{tab?}', 'users/{user}', '/users/7'],
    'two full routes, renamed' => ['users/{id}', 'users/{user}', '/users/7'],
    'a literal after a segment' => ['users/{id}', 'users/me', '/users/me'],
    'a catch-all' => ['{any}', 'users', '/users'],
]);

it('keeps both routes where the router reaches both, whatever the paths share', function (callable $first, callable $second, string $firstUrl, string $secondUrl): void {
    $a = $first($this->route);
    $b = $second($this->route);
    $collection = ($this->collect)($a, $b);

    $served = new ServedForms($collection);

    // Each is the answer to some request, so neither may be reported unreachable.
    expect($collection->match(Request::create($firstUrl)))->toBe($a)
        ->and($collection->match(Request::create($secondUrl)))->toBe($b)
        ->and($served->shadowed($a))->toBe([])
        ->and($served->shadowed($b))->toBe([]);
})->with([
    'disjoint constraints' => [
        static fn (Closure $route): Route => $route(['GET'], 'reports/{year}/{month?}', 'numbers')->whereNumber(['year', 'month']),
        static fn (Closure $route): Route => $route(['GET'], 'reports/{year}', 'names')->where('year', 'summary|latest'),
        '/reports/2024', '/reports/latest',
    ],
    'a narrower route first' => [
        static fn (Closure $route): Route => $route(['GET'], 'users/{id}', 'numbers')->whereNumber('id'),
        static fn (Closure $route): Route => $route(['GET'], 'users/{user}', 'anything'),
        '/users/7', '/users/ada',
    ],
    'a narrower literal set first' => [
        static fn (Closure $route): Route => $route(['GET'], 'users/{id}', 'named')->whereIn('id', ['ada', 'bob']),
        static fn (Closure $route): Route => $route(['GET'], 'users/{user}', 'anything'),
        '/users/ada', '/users/cy',
    ],
    'a host-bound route first' => [
        static fn (Closure $route): Route => $route(['GET'], 'users/{id}', 'admin')->domain('admin.example.com'),
        static fn (Closure $route): Route => $route(['GET'], 'users/{user}', 'anywhere'),
        'http://admin.example.com/users/7', 'http://example.com/users/7',
    ],
    'an HTTPS-only route first' => [
        static fn (Closure $route): Route => new Route(['GET'], 'users/{id}', ['https', 'uses' => static fn (): string => 'secure']),
        static fn (Closure $route): Route => $route(['GET'], 'users/{user}', 'plain'),
        'https://example.com/users/7', 'http://example.com/users/7',
    ],
]);

it('publishes a route\'s own path over another\'s short form where the router reaches both', function (bool $shortFirst): void {
    $numbers = ($this->route)(['GET', 'HEAD'], 'reports/{year}/{month?}', 'numbers')->whereNumber(['year', 'month']);
    $names = ($this->route)(['GET', 'HEAD'], 'reports/{year}', 'names')->where('year', 'summary|latest');

    $served = new ServedForms($shortFirst ? ($this->collect)($numbers, $names) : ($this->collect)($names, $numbers));

    // OpenAPI has room for one operation on GET /reports/{year}, and a short form is the one that yields.
    expect($served->of($names)[0]['methods'])->toBe(['GET', 'HEAD'])
        ->and($served->of($numbers)[1]['methods'])->toBe([])
        ->and($served->of($numbers)[0]['methods'])->toBe(['GET', 'HEAD'])
        ->and($served->contested($numbers))->toBe([
            ['omitted' => ['month'], 'method' => 'GET', 'path' => '/reports/{year}', 'by' => 'GET /reports/{year}'],
            ['omitted' => ['month'], 'method' => 'HEAD', 'path' => '/reports/{year}', 'by' => 'HEAD /reports/{year}'],
        ]);
})->with(['the short form tried first' => [true], 'the route tried first' => [false]]);

it('reports a route a later registration of its URL replaced', function (): void {
    $first = ($this->route)(['GET', 'POST'], 'posts', 'first');
    $second = ($this->route)(['GET'], 'posts', 'second');
    $collection = ($this->collect)($first, $second);

    $served = new ServedForms($collection);

    expect($collection->match(Request::create('/posts')))->toBe($second)
        ->and($served->of($first)[0]['methods'])->toBe(['POST'])
        ->and($served->shadowed($first))->toBe(['GET' => ['by' => '/posts', 'replaced' => true, 'reached' => []], 'HEAD' => ['by' => '/posts', 'replaced' => true, 'reached' => []]]);
});

it('follows the installed router in whether a host-bound route is tried first', function (): void {
    // Laravel 12 tries routes in registration order; Laravel 13 tries every host-bound route first.
    $anywhere = ($this->route)(['GET'], 'users/{id}', 'anywhere');
    $hosted = ($this->route)(['GET'], 'users/me', 'hosted')->domain('api.example.com');
    $collection = ($this->collect)($anywhere, $hosted);

    $served = new ServedForms($collection);
    $winner = $collection->match(Request::create('http://api.example.com/users/me'));

    expect($served->shadowed($hosted) === [])->toBe($winner === $hosted)
        ->and($served->shadowed($anywhere))->toBe([]);
});

it('lets a fallback own nothing, and answers for a route it never saw', function (): void {
    $fallback = ($this->route)(['GET'], '{fallbackPlaceholder}', 'fallback')->where('fallbackPlaceholder', '.*');
    $fallback->isFallback = true;
    $optional = ($this->route)(['GET'], 'posts/{page?}', 'optional');

    $served = new ServedForms(($this->collect)($fallback, $optional));

    expect($served->of($optional))->toHaveCount(2)
        ->and($served->of($optional)[1]['methods'])->toBe(['GET', 'HEAD'])
        ->and($served->shadowed($optional))->toBe([])
        ->and((new ServedForms(new RouteCollection))->of($optional))->toBe([['uri' => 'posts/{page?}', 'omitted' => [], 'methods' => ['GET', 'HEAD']]]);
});

it('answers alike whichever object a collection hands out for a route', function (): void {
    $routes = [
        ($this->route)(['GET', 'HEAD'], 'users/{id}', 'any'),
        ($this->route)(['GET', 'HEAD'], 'users/me', 'me'),
        ($this->route)(['GET', 'HEAD'], 'posts/{page?}', 'posts'),
        ($this->route)(['GET', 'HEAD'], 'a/{x}/{p?}', 'x')->whereNumber('x'),
        ($this->route)(['GET', 'HEAD'], 'a/{y}/{q?}', 'y')->whereAlpha('y'),
    ];
    $plain = ($this->collect)(...$routes);
    $answers = static function (RouteCollectionInterface $collection): array {
        $served = new ServedForms($collection);
        $answers = [];
        foreach ($collection->getRoutes() as $route) {
            $answers[ServedForms::key($route)] = [$served->of($route), $served->shadowed($route), $served->contested($route)];
        }
        ksort($answers);

        return $answers;
    };
    $before = $answers($plain);

    // A cached router's collection builds a new route object each time one is asked for.
    $compiled = $plain->toCompiledRouteCollection(app('router'), app());
    expect($compiled->getRoutes()[0])->not->toBe($compiled->getRoutes()[0])
        ->and($answers($compiled))->toBe($before)
        ->and($before[ServedForms::key($routes[1])][1])->toHaveKey('GET')
        ->and($before[ServedForms::key($routes[2])][0][1]['methods'])->toBe(['GET', 'HEAD']);
});

it('says which shorter forms still reach a route whose full form another answers for', function (): void {
    $full = ($this->route)(['GET'], 'users/{id}', 'full');
    $optional = ($this->route)(['GET'], 'users/{id?}', 'optional');
    $collection = ($this->collect)($full, $optional);

    $served = new ServedForms($collection);

    expect($collection->match(Request::create('/users/7')))->toBe($full)
        ->and($collection->match(Request::create('/users')))->toBe($optional)
        ->and($served->shadowed($optional))->toBe([
            'GET' => ['by' => '/users/{id}', 'replaced' => false, 'reached' => ['/users']],
            'HEAD' => ['by' => '/users/{id}', 'replaced' => false, 'reached' => ['/users']],
        ])
        ->and($served->of($optional)[1]['methods'])->toBe(['GET', 'HEAD']);
});

it('publishes one of two short forms on a path whichever route is tried first', function (bool $numberedFirst): void {
    $numbered = ($this->route)(['GET'], 'a/{x}/{p?}', 'x')->whereNumber('x');
    $lettered = ($this->route)(['GET'], 'a/{y}/{q?}', 'y')->whereAlpha('y');
    $collection = $numberedFirst ? ($this->collect)($numbered, $lettered) : ($this->collect)($lettered, $numbered);

    $served = new ServedForms($collection);

    // The router serves both, and never on one request; the document has room for one of them.
    expect($collection->match(Request::create('/a/7')))->toBe($numbered)
        ->and($collection->match(Request::create('/a/ada')))->toBe($lettered)
        ->and($served->of($numbered)[1]['methods'])->toBe(['GET', 'HEAD'])
        ->and($served->of($lettered)[1]['methods'])->toBe([])
        ->and(array_column($served->contested($lettered), 'by', 'method'))->toBe(['GET' => 'GET /a/{x}/{p?}', 'HEAD' => 'HEAD /a/{x}/{p?}']);
})->with(['the numbered route first' => [true], 'the lettered route first' => [false]]);

it('holds a route\'s own path over another\'s short form on another host', function (bool $shortFirst): void {
    $short = ($this->route)(['GET'], 'reports/{year}/{month?}', 'short')->whereNumber(['year', 'month']);
    $own = ($this->route)(['GET'], 'reports/{year}', 'own')->where('year', 'summary|latest')->domain('api.example.com');

    $served = new ServedForms($shortFirst ? ($this->collect)($short, $own) : ($this->collect)($own, $short));

    // OpenAPI keys an operation by path and method alone, so the host does not make the slot another.
    expect($served->of($own)[0]['methods'])->toBe(['GET', 'HEAD'])
        ->and($served->of($short)[1]['methods'])->toBe([])
        ->and(array_column($served->contested($short), 'by', 'method'))->toBe(['GET' => 'GET api.example.com/reports/{year}', 'HEAD' => 'HEAD api.example.com/reports/{year}']);
})->with(['the short form first' => [true], 'the own path first' => [false]]);

it('never says a route is unreached that the router serves a request with', function (): void {
    $specs = [
        ['users/{id}', []], ['users/{user}', ['user' => '[0-9]+']], ['users/{u}', ['u' => '\d+']], ['users/me', []],
        ['users/{id?}', []], ['users/{id}/{tab?}', []], ['users/{id}/{tab?}', ['id' => '[a-z]+']], ['users', []],
        ['users/{id}', ['id' => '[\.-z]+']], ['users/{id}', ['id' => '[a-z.\-]+']], ['users/{id}', ['id' => 'ada|bob']],
        ['users/{id}', ['id' => '[^/]+']], ['users/{id}', ['id' => '.*']], ['users/{id}', ['id' => '[\w]+']], ['users/{id}', ['id' => '[a-zA-Z0-9_]+']],
        ['{any}', ['any' => '.*']], ['{any?}', []], ['users/{a}.{b}', []], ['users/{a}.{b?}', []], ['users/{a}', ['a' => '[^.]+']],
        ['users/{a}.{b}', ['a' => '[a-z.]++']], ['users/{a}.{b}', ['a' => '[a-z]+']], ['users/{a}.{b}', ['a' => '[a-z]++']],
        ['users/{id}', ['id' => '[!-\.]+']], ['users/{id}', ['id' => '[!-.]+']], ['users/{id}', ['id' => '[\x41-\x5a]+']],
        ['users/{id}', ['id' => '[[:alpha:]]+']], ['users/{id}', ['id' => '[a-z]*']], ['users/{id}', ['id' => '\w+']], ['users/{id}', ['id' => '[^\d]+']],
        ['users/{id}', ['id' => '(?i)[a-z]+']], ['users/{id}', ['id' => '[A-Z]+']], ['users/{id}', ['id' => '[\-]+']], ['users/{id}', ['id' => '[a\-z]+']],
    ];
    $urls = ['/users', '/users/7', '/users/me', '/users/ada', '/users/ADA', '/users/a.b', '/users/x.y', '/users/ab.y', '/users/a.', '/users/.', '/users/-', '/users/_', '/users/é', '/users/٣', '/users/7/x', '/users/ada/x', '/', '/users/!', '/users/,', '/users/A', '/users/a-z', '/x', '/users/%2F', '/users/a%2Fb', '/users/Ada', '/users/a.b.c'];

    $unsound = [];
    $shadows = 0;
    foreach ($specs as $first) {
        foreach ($specs as $second) {
            $routes = array_map(static function (array $spec): Route {
                $route = new Route(['GET'], $spec[0], static fn (): null => null);
                foreach ($spec[1] as $name => $expression) {
                    $route->where($name, $expression);
                }

                return $route;
            }, [$first, $second]);
            $collection = ($this->collect)(...$routes);
            if (count($collection->getRoutes()) < 2) {
                continue;
            }

            $served = new ServedForms($collection);
            foreach ($urls as $url) {
                try {
                    $winner = $collection->match(Request::create($url));
                } catch (Throwable) {
                    continue;
                }
                $shadow = $served->shadowed($winner)['GET'] ?? null;
                if ($shadow === null) {
                    continue;
                }
                // A route said to be unreached is served nothing; one reached only at its shorter forms is
                // never served a URL its full form — every segment given — matches.
                $full = (new Route(['GET'], str_replace('?}', '}', $winner->uri()), static fn (): null => null))->setWheres($winner->wheres);
                if ($shadow['reached'] === [] || $full->matches(Request::create($url))) {
                    $unsound[] = json_encode([$first, $second, $url]);
                }
            }
            foreach ($routes as $route) {
                $shadows += isset($served->shadowed($route)['GET']) ? 1 : 0;
            }
        }
    }

    expect($unsound)->toBe([])
        ->and($shadows)->toBeGreaterThan(60);
});
