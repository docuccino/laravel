<?php

declare(strict_types=1);

use Docuccino\Laravel\Routing\RouteTemplate;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Which `{param?}`s the router really lets a request leave off. Its compiler is Symfony's, so it is the
 * oracle: a segment is optional when the route matches a request that leaves it off.
 */
it('reads the optional segments the router compiles, and no other', function (string $uri, array $optional): void {
    expect(RouteTemplate::optional($uri))->toBe($optional);

    // The oracle is the router: a segment is optional when the route still matches a request that ends
    // just before it — the segment, the separator ahead of it, and everything after it gone.
    $route = new Route(['GET'], $uri, static fn (): null => null);
    $droppable = [];
    foreach (array_keys($route->getOptionalParameterNames()) as $name) {
        $at = (int) strpos($uri, '{'.$name.'?}');
        $prefix = substr($uri, 0, $at > 0 && str_contains('/,;.:-_~+*=@|', $uri[$at - 1]) ? $at - 1 : $at);

        if ($route->matches(Request::create('/'.preg_replace('/\{[^}]+}/', 'v', $prefix)))) {
            $droppable[] = $name;
        }
    }

    expect($droppable)->toBe($optional);
})->with([
    'a trailing segment' => ['posts/{page?}', ['page']],
    'two trailing segments' => ['posts/{year?}/{month?}', ['year', 'month']],
    'after a required one' => ['posts/{post}/{page?}', ['page']],
    // Followed by literal text or a required segment, the router cannot tell where it would have ended.
    'before literal text' => ['posts/{page?}/items', []],
    'before a required segment' => ['posts/{page?}/{item}', []],
    'only the trailing run' => ['posts/{a?}/x/{b?}', ['b']],
    'after another separator' => ['files/{name}.{format?}', ['format']],
    'none' => ['posts/{post}', []],
]);

it('lists every URL form the router serves for a template, longest first', function (string $uri, array $forms): void {
    expect(RouteTemplate::forms($uri))->toBe($forms);

    // The oracle is the router: each form's URL, its remaining segments filled in, is one it matches.
    $route = new Route(['GET'], $uri, static fn (): null => null);
    foreach ($forms as $form) {
        expect($route->matches(Request::create('/'.preg_replace('/\{[^}]+}/', 'v', $form['uri']))))->toBeTrue();
    }
})->with([
    'none' => ['posts/{post}', [['uri' => 'posts/{post}', 'omitted' => []]]],
    'one' => ['posts/{page?}', [['uri' => 'posts/{page?}', 'omitted' => []], ['uri' => 'posts', 'omitted' => ['page']]]],
    'two' => ['posts/{year?}/{month?}', [
        ['uri' => 'posts/{year?}/{month?}', 'omitted' => []],
        ['uri' => 'posts/{year?}', 'omitted' => ['month']],
        ['uri' => 'posts', 'omitted' => ['year', 'month']],
    ]],
    'after another separator' => ['files/{name}.{format?}', [['uri' => 'files/{name}.{format?}', 'omitted' => []], ['uri' => 'files/{name}', 'omitted' => ['format']]]],
    'at the root' => ['{page?}', [['uri' => '{page?}', 'omitted' => []], ['uri' => '', 'omitted' => ['page']]]],
    'before literal text' => ['posts/{page?}/items', [['uri' => 'posts/{page?}/items', 'omitted' => []]]],
]);

it('reads the parameter names the route binds, the optional marker aside', function (string $template, array $names): void {
    expect(RouteTemplate::parameters($template))->toBe($names)
        // The oracle: the names the route itself binds, host segments first.
        ->and((new Route(['GET'], $template, static fn (): null => null))->parameterNames())->toBe($names);
})->with([
    'none' => ['posts', []],
    'required and optional' => ['posts/{post}/{page?}', ['post', 'page']],
    'beside literal text' => ['files/{name}.{format?}', ['name', 'format']],
]);
