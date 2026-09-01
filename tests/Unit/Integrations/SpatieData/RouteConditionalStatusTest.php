<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Integrations\SpatieData\RouteConditionalStatus;
use Docuccino\Laravel\Tests\Support\StubTraceScope;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * The route-name fold's grammar, over REAL php-parser nodes with a stub TypeScope: which
 * `calculateResponseStatus()` bodies reduce to a decision the route already settles, and — the half that
 * matters more — which ones must not. Every "leaves it alone" row is the code the guard should refuse,
 * executed. The real-engine half (a class constant folded by PHPStan, the walk driven by the Tracer) is
 * proven in the fixture group.
 *
 * Snippets name classes fully-qualified: the stub scope has no namespace-resolution scope, exactly as the
 * Query Builder's snippets do.
 */
function walkStatusBody(string $body, array $variableTypes = []): RouteConditionalStatus
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse("<?php\n".$body."\n") ?? [];

    $visitor = new RouteConditionalStatus;
    $scope = new StubTraceScope(
        new ClassT('stdClass'),
        variableTypes: $variableTypes + ['request' => new ClassT('Illuminate\\Http\\Request')],
    );

    $traverser = new NodeTraverser(new class($visitor, $scope) extends NodeVisitorAbstract
    {
        public function __construct(
            private readonly RouteConditionalStatus $visitor,
            private readonly StubTraceScope $scope,
        ) {}

        public function enterNode(Node $node): ?int
        {
            $this->visitor->enterNode($node, $this->scope);

            return null;
        }
    });
    $traverser->traverse($ast);

    return $visitor;
}

/** The reported shape, written the way spatie's own docs write it: a class constant per arm. */
function routeIsBody(string $patterns = "'*things.store'"): string
{
    return 'return $request->routeIs('.$patterns.') ? \\Illuminate\\Http\\Response::HTTP_CREATED : \\Illuminate\\Http\\Response::HTTP_OK;';
}

it('narrows a route-name ternary to the status each route takes', function (?string $name, int $expected): void {
    expect(walkStatusBody(routeIsBody())->statusFor($name))->toBe($expected);
})->with([
    // The create endpoint the pattern names: the only one that can answer 201.
    'the named create route' => ['things.store', 201],
    // A sibling POST — same verb, different name, so the 201 arm is unreachable for it.
    'a sibling POST route' => ['things.publish', 200],
    // The reported symptom: a GET carrying a 201 the server can never send.
    'a read route' => ['things.show', 200],
    // Route::named() answers false for an unnamed route before it looks at a pattern at all.
    'a route with no name' => [null, 200],
]);

it('reads the two spellings of the same runtime call alike', function (string $body): void {
    // routeIs() IS route()->named(); an override written the long way must fold the same.
    $visitor = walkStatusBody($body);

    expect($visitor->statusFor('things.store'))->toBe(201)
        ->and($visitor->statusFor('things.show'))->toBe(200);
})->with([
    '$request->routeIs(…)' => [routeIsBody()],
    '$request->route()->named(…)' => ["return \$request->route()->named('*things.store') ? 201 : 200;"],
]);

it('folds a negated predicate by swapping which arm the match takes', function (): void {
    $visitor = walkStatusBody("return ! \$request->routeIs('*things.store') ? 200 : 201;");

    expect($visitor->statusFor('things.store'))->toBe(201)
        ->and($visitor->statusFor('things.show'))->toBe(200);
});

it('matches any of several patterns in one call', function (string $name, int $expected): void {
    expect(walkStatusBody(routeIsBody("'*things.store', '*things.publish'"))->statusFor($name))->toBe($expected);
})->with([
    'the first pattern' => ['things.store', 201],
    'the second pattern' => ['things.publish', 201],
    'neither' => ['things.show', 200],
]);

it('reads a spread the call site wrote out as the positions it really takes', function (): void {
    // Placement is FoldedArguments': a list literal spread IS its items, so the patterns are as readable
    // as if they had been written one per argument. The unreadable spread stays refused, below.
    $visitor = walkStatusBody("return \$request->routeIs(...['*things.store', '*things.publish']) ? 201 : 200;");

    expect($visitor->statusFor('things.store'))->toBe(201)
        ->and($visitor->statusFor('things.publish'))->toBe(201)
        ->and($visitor->statusFor('things.show'))->toBe(200);
});

it("matches a glob through Laravel's own Str::is, wildcard included", function (string $name, int $expected): void {
    expect(walkStatusBody(routeIsBody("'things.*'"))->statusFor($name))->toBe($expected);
})->with([
    'a name the wildcard covers' => ['things.store', 201],
    'a name under another prefix' => ['widgets.store', 200],
    // `Str::is` anchors both ends: a prefix match is not a match.
    'a name the prefix only starts' => ['things', 200],
]);

it('leaves the union alone for every shape it does not recognise', function (string $body, array $variableTypes = []): void {
    expect(walkStatusBody($body, $variableTypes)->statusFor('things.store'))->toBeNull();
})->with([
    // Two returns: the guard-clause form and the same body written the other way round. One `return` of
    // one ternary is the whole recognised shape, and neither of these is it.
    'an if-guard clause' => ["if (\$request->routeIs('*things.store')) {\n    return 201;\n}\n\nreturn 200;"],
    'two plain return statements' => ["if (\$request->getMethod() === 'POST') {\n    return 201;\n}\n\nreturn 200;"],
    // The ternary is there, but so is a second return — order must not decide it, so both ways round.
    'a ternary return followed by another return' => [routeIsBody()."\n\nreturn 200;"],
    'a return before the ternary return' => ["if (false) {\n    return 200;\n}\n\n".routeIsBody()],

    // Arms that are not constant ints.
    'an arm that is a variable' => ["return \$request->routeIs('*things.store') ? 201 : \$fallback;"],
    'an arm that is a string' => ["return \$request->routeIs('*things.store') ? '201' : 200;"],
    'a short ternary, which has no second arm' => ["return \$request->routeIs('*things.store') ?: 200;"],

    // Patterns that are not constant strings, or cannot be placed. A list read short would narrow to a
    // status the server may well not send.
    'a non-constant pattern' => ['return $request->routeIs($pattern) ? 201 : 200;'],
    'one constant pattern beside a non-constant one' => ["return \$request->routeIs('*things.store', \$pattern) ? 201 : 200;"],
    'a spread of patterns' => ['return $request->routeIs(...$patterns) ? 201 : 200;'],
    // A first-class callable passes no arguments at all; as a condition it is a truthy Closure, and a
    // pattern list read off it would be empty.
    'a first-class callable' => ['return $request->routeIs(...) ? 201 : 200;'],
    'a named argument' => ["return \$request->routeIs(patterns: '*things.store') ? 201 : 200;"],
    'no pattern at all' => ['return $request->routeIs() ? 201 : 200;'],

    // The receiver has to be the request. A same-named method on something else is not this predicate.
    'routeIs() on something that is not a request' => [
        "return \$other->routeIs('*things.store') ? 201 : 200;",
        ['other' => new ClassT('App\\Support\\Sniffer')],
    ],
    'named() on something that is not the request route' => [
        "return \$other->route()->named('*things.store') ? 201 : 200;",
        ['other' => new ClassT('App\\Support\\Sniffer')],
    ],
    // `route('id')` reads a route PARAMETER; only the argument-less accessor answers the Route object.
    'named() on a route parameter read' => ["return \$request->route('thing')->named('*things.store') ? 201 : 200;"],

    // Deliberately outside the boundary: decidable from the descriptor, but unmeasured.
    'the HTTP method' => ["return \$request->isMethod('POST') ? 201 : 200;"],
    'the request URI' => ["return \$request->is('things/*') ? 201 : 200;"],
    // Outside the boundary altogether — runtime state no build holds.
    'a predicate over runtime state' => ['return $request->user()->isAdmin() ? 201 : 200;'],
]);

it('never folds a body with no return at all', function (): void {
    // The floor under every row above: nothing seen means nothing narrowed, not a default.
    expect(walkStatusBody('$request->routeIs(\'*things.store\');')->statusFor('things.store'))->toBeNull();
});
