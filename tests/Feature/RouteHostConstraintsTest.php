<?php

declare(strict_types=1);

use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use Docuccino\Laravel\Tests\Fixtures\RouteConstraints\ConstraintController;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/**
 * The router compiles a route's HOST from the same constraints as its path, so `{tenant}` in
 * `Route::domain()` answers only for the values its `->where()` allows and every other host is a 404.
 * A host segment reaches the document as a server variable, and a Server Variable Object has `enum` and
 * `default` but no `pattern`: a closed set is published as the enum, with a default drawn from it —
 * OpenAPI requires the default to be one of the enum's values — and any other constraint is stated in
 * the description, the one place the object leaves for it.
 */
beforeEach(function (): void {
    $this->variableOf = static function (callable $routes, string $name = 'tenant'): array {
        $result = localityBuild($routes);
        $servers = $result->document->toArray()['paths']['/api/zz-hosted']['get']['servers'];

        return [$servers[0]['variables'][$name], $result];
    };
});

it('publishes a closed set on a host segment as the variable\'s enum, defaulting to a member of it', function (callable $register, array $enum): void {
    [$variable, $result] = ($this->variableOf)(static function (Router $router) use ($register): void {
        $register($router);
    });

    expect($variable['enum'])->toBe($enum)
        ->and($variable['default'])->toBe($enum[0])
        ->and($variable['description'])->toBe('The "tenant" segment of the host this operation is served from.');

    // Each version's meta-schema holds the default to the enum, so the answer is checked against it.
    foreach (['openapi-3.0', 'openapi-3.1', 'openapi-3.2'] as $format) {
        $emitted = json_decode(Formats::emit($format, $result->document, new EmitOptions)->output, flags: JSON_THROW_ON_ERROR);
        expect(OpenApiMetaSchema::findings($format, $emitted))->toBe([]);
    }
})->with([
    'whereIn' => [static fn (Router $router) => $router->domain('{tenant}.example.com')->get('api/zz-hosted', [ConstraintController::class, 'hosted'])->whereIn('tenant', ['globex', 'acme']), ['globex', 'acme']],
    'a global pattern' => [static function (Router $router): void {
        $router->pattern('tenant', 'acme|globex');
        $router->domain('{tenant}.example.com')->get('api/zz-hosted', [ConstraintController::class, 'hosted']);
    }, ['acme', 'globex']],
    'an optional marker' => [static fn (Router $router) => $router->domain('{tenant?}.example.com')->get('api/zz-hosted', [ConstraintController::class, 'hosted'])->whereIn('tenant', ['acme']), ['acme']],
]);

const HOST_VARIABLE_DEFAULT_REFUSED = 'The default only names the segment, and is not a value it accepts.';

it('states a constraint no Server Variable keyword can carry in its description', function (callable $constrain, ?string $note): void {
    [$variable] = ($this->variableOf)(static function (Router $router) use ($constrain): void {
        $constrain($router->domain('{tenant}.example.com')->get('api/zz-hosted', [ConstraintController::class, 'hosted']));
    });

    $description = 'The "tenant" segment of the host this operation is served from.';

    // The router matches a host ignoring case, so a pattern is stated with that; a pattern ECMA-262 reads
    // differently, or a catch-all, says nothing true, so nothing is added for either.
    expect($variable)->toBe([
        'default' => 'tenant',
        'description' => $note === null ? $description : $description.' '.$note,
    ]);
})->with([
    'a pattern' => [static fn ($route) => $route->where('tenant', '[a-z]+'), 'It matches the pattern `^[a-z]+$`, ignoring case.'],
    'whereAlpha' => [static fn ($route) => $route->whereAlpha('tenant'), 'It matches the pattern `^[a-zA-Z]+$`, ignoring case.'],
    // OpenAPI requires a default and nothing else names a host the route answers on, so the segment's name
    // stays one — and where the constraint refuses it, the description says it is no value at all.
    'whereUuid' => [static fn ($route) => $route->whereUuid('tenant'), 'It is a UUID. '.HOST_VARIABLE_DEFAULT_REFUSED],
    'whereUlid' => [static fn ($route) => $route->whereUlid('tenant'), 'It is a ULID. '.HOST_VARIABLE_DEFAULT_REFUSED],
    'a catch-all' => [static fn ($route) => $route->where('tenant', '.+'), null],
    'unportable' => [static fn ($route) => $route->where('tenant', '\d{4}'), HOST_VARIABLE_DEFAULT_REFUSED],
    // whereIn does not escape its values, so `v1.0` also matches `v1x0` and is no closed set.
    'literals that are not a closed set' => [static fn ($route) => $route->whereIn('tenant', ['v1.0']), HOST_VARIABLE_DEFAULT_REFUSED],
    'none' => [static fn ($route) => $route, null],
    // A host the router matches always carries the segment, so the route's default is never what the
    // action receives and is not published as the variable's.
    'a route default' => [static fn ($route) => $route->defaults('tenant', 'acme'), null],
]);

it('answers for the host a request carries exactly as the published enum says', function (): void {
    $register = static function (Router $router): void {
        $router->domain('{tenant}.example.com')->get('api/zz-hosted', [ConstraintController::class, 'hosted'])->whereIn('tenant', ['acme', 'globex']);
    };
    [$variable] = ($this->variableOf)($register);

    app('router')->setRoutes(new RouteCollection);
    $register(app('router'));

    // The framework is the oracle: every enum value is served, and a host outside it is not.
    foreach ($variable['enum'] as $value) {
        $this->getJson('http://'.$value.'.example.com/api/zz-hosted')->assertOk();
    }
    $this->getJson('http://initech.example.com/api/zz-hosted')->assertNotFound();
});

it('keys the fragment on a host constraint, so a warm build equals a cold one', function (): void {
    $before = static function (Router $router): void {
        $router->domain('{tenant}.example.com')->get('api/zz-hosted', [ConstraintController::class, 'hosted']);
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item']);
    };
    $after = static function (Router $router): void {
        $router->domain('{tenant}.example.com')->get('api/zz-hosted', [ConstraintController::class, 'hosted'])->whereIn('tenant', ['acme']);
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item']);
    };

    $warm = assertWarmEqualsCold($before, $after);

    expect($warm->document->toArray()['paths']['/api/zz-hosted']['get']['servers'][0]['variables']['tenant']['enum'])->toBe(['acme']);
});
