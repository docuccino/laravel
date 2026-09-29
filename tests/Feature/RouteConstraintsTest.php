<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Extensions\PathParametersExtension;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
use Docuccino\Laravel\Tests\Fixtures\RouteConstraints\ConstraintController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\CreatesRegularExpressionRouteConstraints;
use Illuminate\Routing\Router;

/**
 * A route constraint is a fact about what the server accepts, stated as data on the route: a segment
 * that does not match it is a 404 and never reaches the action. So a segment nothing else typed is
 * published as what the router matches — and never as something narrower, which would mark a working
 * request invalid, or as a regex that means something else in JSON Schema's ECMA-262 dialect.
 */
afterEach(fn () => removeFragmentCacheDirs('route-constraints'));

beforeEach(function (): void {
    $this->schemaOf = static function (callable $routes, string $path, string $name = 'item', ?callable $engine = null): array {
        $result = localityBuild($routes, $engine);
        $parameter = pathParameter($result->document->toArray()['paths'][$path]['get'], $name);

        expect($parameter)->not->toBeNull();

        return [array_diff_key($parameter['schema'], ['x-docuccino' => true]), $result->diagnostics, $parameter];
    };
});

it('publishes each framework shorthand as the value set the router matches', function (string $shorthand, array $expected): void {
    [$schema] = ($this->schemaOf)(static function (Router $router) use ($shorthand): void {
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item'])->{$shorthand}('item');
    }, '/api/zz-items/{item}');

    // The UUID and ULID shorthands are the formats a UUID- or ULID-keyed binding already publishes; the
    // rest are portable as written, anchored because the router matches the whole segment.
    expect($schema)->toBe($expected);
})->with([
    'whereUuid' => ['whereUuid', ['type' => 'string', 'format' => 'uuid']],
    'whereUlid' => ['whereUlid', ['type' => 'string', 'format' => 'ulid']],
    'whereNumber' => ['whereNumber', ['type' => 'string', 'pattern' => '^[0-9]+$']],
    'whereAlpha' => ['whereAlpha', ['type' => 'string', 'pattern' => '^[a-zA-Z]+$']],
    'whereAlphaNumeric' => ['whereAlphaNumeric', ['type' => 'string', 'pattern' => '^[a-zA-Z0-9]+$']],
]);

it('covers every shorthand the framework declares', function (): void {
    // The dataset above only proves the rows it lists; this reads the framework's own list.
    $shorthands = array_values(array_filter(
        (new ReflectionClass(CreatesRegularExpressionRouteConstraints::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => $method->getName() !== 'whereIn',
    ));
    $names = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $shorthands);
    sort($names);

    expect($names)->toBe(['whereAlpha', 'whereAlphaNumeric', 'whereNumber', 'whereUlid', 'whereUuid']);
});

it('publishes a whereIn set as its enum', function (): void {
    [$schema] = ($this->schemaOf)(static function (Router $router): void {
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item'])->whereIn('item', ['draft', 'published']);
    }, '/api/zz-items/{item}');

    // A closed set the router enforces owes the document an enum.
    expect($schema['enum'])->toBe(['draft', 'published'])
        ->and($schema['type'])->toBe('string')
        ->and($schema)->not->toHaveKey('pattern');
});

it('never publishes an enum narrower than the expression the router matches', function (): void {
    [$schema, $diagnostics] = ($this->schemaOf)(static function (Router $router): void {
        // whereIn does not escape its values: this route also answers `/v1x0`, so an enum of the two
        // literals would reject a request the server serves.
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item'])->whereIn('item', ['v1.0', 'v2.0']);
    }, '/api/zz-items/{item}');

    expect($schema)->toBe(['type' => 'string'])
        ->and(diagnosticsCoded($diagnostics, 'route-constraint.unportable'))->toHaveCount(1);
});

it('publishes a hand-written constraint as the anchored pattern the router applies', function (string $expression, array $expected): void {
    [$schema] = ($this->schemaOf)(static function (Router $router) use ($expression): void {
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item'])->where('item', $expression);
    }, '/api/zz-items/{item}');

    expect($schema)->toBe($expected);
})->with([
    'a class' => ['[a-z.]+', ['type' => 'string', 'pattern' => '^[a-z.]+$']],
    // The router strips the author's own anchors before embedding the expression, and so does the reader.
    'already anchored' => ['^[a-z]{2,8}$', ['type' => 'string', 'pattern' => '^[a-z]{2,8}$']],
    // Anchoring an alternation ungrouped would bind `^` to the first branch and `$` to the last.
    'an alternation' => ['[0-9]+|latest', ['type' => 'string', 'pattern' => '^(?:[0-9]+|latest)$']],
    // Under the router's `u` `\d` is every script's digit: ASCII digits or any non-ASCII character is wider, and true.
    'a Unicode-wide escape' => ['\d+', ['type' => 'string', 'pattern' => '^(?:[0-9]|[^\x00-\x7F])+$']],
    'a Unicode-wide escape in a class' => ['[\w-]+', ['type' => 'string', 'pattern' => '^(?:[0-9A-Za-z_-]|[^\x00-\x7F])+$']],
    // PCRE reads escaped punctuation as itself; ECMA-262 refuses `\-` outside a class under `u`.
    'escaped punctuation' => ['[a-z]+\-[a-z]+', ['type' => 'string', 'pattern' => '^[a-z]+-[a-z]+$']],
    // Escaped, the same versions are a closed set, decorated like every other published one.
    'escaped literals' => ['v1\.0|v2\.0', [
        'type' => 'string',
        'enum' => ['v1.0', 'v2.0'],
        'x-enum-varnames' => ['V10', 'V20'],
        'x-enumNames' => ['V10', 'V20'],
    ]],
]);

it('leaves a constraint JSON Schema would read differently as a plain string, and says so', function (string $expression): void {
    [$schema, $diagnostics] = ($this->schemaOf)(static function (Router $router) use ($expression): void {
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item'])->where('item', $expression);
    }, '/api/zz-items/{item}');

    $reports = diagnosticsCoded($diagnostics, 'route-constraint.unportable');

    expect($schema)->toBe(['type' => 'string'])
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->message)->toContain('{item}')
        ->and($reports[0]->message)->toContain($expression);
})->with([
    // The router counts code points; a JSON Schema validator without `u` counts UTF-16 units, so `😀😀`
    // is four of them, and a count over a class that takes it would refuse what the router serves.
    'a count over a Unicode-wide escape' => ['\d{4}'],
    'a count over a negated class' => ['[^/]{2}'],
    'a case-insensitive flag' => ['(?i)[a-z]+'],
    'a possessive quantifier' => ['[a-z]++'],
]);

it('publishes a Unicode-wide constraint no narrower than the router', function (): void {
    [$schema] = ($this->schemaOf)(static function (Router $router): void {
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item'])->where('item', '\d+');
    }, '/api/zz-items/{item}');

    // The router serves an Arabic-Indic digit, so the pattern must accept it — in UTF mode without
    // Unicode properties, which is how ECMA-262 reads a class, and with a character outside the BMP.
    expect(preg_match('{^\d+$}sDu', '٣𝟎'))->toBe(1)
        ->and(preg_match('/(*UTF)'.$schema['pattern'].'/', '٣𝟎'))->toBe(1)
        ->and(preg_match('/(*UTF)'.$schema['pattern'].'/', 'x'))->toBe(0);
});

it('says nothing about a catch-all, which a plain string already describes', function (): void {
    [$schema, $diagnostics] = ($this->schemaOf)(static function (Router $router): void {
        $router->get('api/zz-files/{item}', [ConstraintController::class, 'item'])->where('item', '.*');
    }, '/api/zz-files/{item}');

    expect($schema)->toBe(['type' => 'string'])
        ->and(diagnosticsCoded($diagnostics, 'route-constraint.unportable'))->toBe([]);
});

it('applies a global pattern to every route using the segment name, and to no other', function (): void {
    $routes = static function (Router $router): void {
        $router->pattern('item', '[0-9]+');
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item']);
        $router->get('api/zz-pairs/{first}/{second}', [ConstraintController::class, 'pair']);
    };

    [$constrained] = ($this->schemaOf)($routes, '/api/zz-items/{item}');
    [$unrelated] = ($this->schemaOf)($routes, '/api/zz-pairs/{first}/{second}', 'first');

    expect($constrained)->toBe(['type' => 'string', 'pattern' => '^[0-9]+$'])
        ->and($unrelated)->toBe(['type' => 'string']);
});

it('lets the route override a global pattern, as the router does', function (): void {
    [$schema] = ($this->schemaOf)(static function (Router $router): void {
        $router->pattern('item', '[0-9]+');
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item'])->whereAlpha('item');
    }, '/api/zz-items/{item}');

    expect($schema)->toBe(['type' => 'string', 'pattern' => '^[a-zA-Z]+$']);
});

it('keeps a bound model\'s key schema over the route constraint', function (): void {
    [$schema] = ($this->schemaOf)(
        static function (Router $router): void {
            $router->get('api/zz-vaults/{vault}', [ConstraintController::class, 'vault'])->where('vault', '[0-9a-f-]+');
        },
        '/api/zz-vaults/{vault}',
        'vault',
        static fn (): TypeEngine => WorkbenchEngine::make(classOverrides: [
            Vault::class => new ClassMetadata(Vault::class, [new PropertyMetadata('id', ScalarT::string())]),
        ]),
    );

    expect($schema)->toBe(['type' => 'string', 'format' => 'uuid']);
});

it('answers from the constraint where a binding could not be typed, and drops the notice saying it was not', function (): void {
    $context = static fn (array $constraints): RouteContext => new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things/{thing}'),
        actionRef: new ActionRef('', null, 'show'),
        attributes: new AttributeSet([]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
        pathParameters: ['thing'],
        // A bound class no resolver answers for, so nothing but the route says what the segment holds.
        routeBindings: ['thing' => 'Workbench\\App\\Support\\Thing'],
        pathParameterConstraints: $constraints,
    );

    $run = static function (RouteContext $context): array {
        $operation = new OperationDraft;
        (new PathParametersExtension)->handle($operation, $context);
        $schema = $operation->parameter('path', 'thing')->freeze()->toArray()['schema'];

        return [
            array_diff_key($schema, ['x-docuccino' => true]),
            array_map(static fn ($d): string => $d->code, $context->components->diagnostics()),
        ];
    };

    [$constrained, $quiet] = $run($context(['thing' => '[a-z]+']));
    [$plain, $reported] = $run($context([]));

    expect($constrained)->toBe(['type' => 'string', 'pattern' => '^[a-z]+$'])
        ->and($quiet)->not->toContain('route-binding.untyped')
        // The control: the same binding with no constraint is the plain string the notice describes.
        ->and($plain)->toBe(['type' => 'string'])
        ->and($reported)->toContain('route-binding.untyped');
});

it('lets #[PathParameter] win over the route constraint', function (): void {
    [$typed] = ($this->schemaOf)(static function (Router $router): void {
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'declared'])->whereNumber('item');
    }, '/api/zz-items/{item}');

    [$described, , $parameter] = ($this->schemaOf)(static function (Router $router): void {
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'described'])->whereAlpha('item');
    }, '/api/zz-items/{item}');

    // A declared type states its shape whole, so the string pattern goes with the string it refined; a
    // declaration that only adds a format and prose keeps the constraint beside them.
    expect($typed)->toBe(['type' => 'integer'])
        ->and($described)->toBe(['type' => 'string', 'pattern' => '^[a-zA-Z]+$', 'format' => 'uuid'])
        ->and($parameter['description'])->toBe('The item.');
});

it('keys the fragment on the constraint, so a warm build equals a cold one', function (): void {
    // The unportable route is unchanged, so it is served warm and its notice has to travel on the fragment.
    $before = static function (Router $router): void {
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item']);
        $router->get('api/zz-digits/{item}', [ConstraintController::class, 'item'])->where('item', '\d{4}');
    };
    $after = static function (Router $router): void {
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item'])->whereUuid('item');
        $router->get('api/zz-digits/{item}', [ConstraintController::class, 'item'])->where('item', '\d{4}');
    };

    $warm = assertWarmEqualsCold($before, $after);
    $parameter = pathParameter($warm->document->toArray()['paths']['/api/zz-items/{item}']['get'], 'item');

    expect($parameter['schema']['format'] ?? null)->toBe('uuid')
        ->and(diagnosticsCoded($warm->diagnostics, 'route-constraint.unportable'))->toHaveCount(1);
});

it('publishes constrained segments byte-identical to its committed golden', function (): void {
    $result = localityBuild(static function (Router $router): void {
        $router->pattern('code', '[a-z]{3}');
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item'])->whereUuid('item');
        $router->get('api/zz-tokens/{item}', [ConstraintController::class, 'item'])->whereUlid('item');
        $router->get('api/zz-numbers/{item}', [ConstraintController::class, 'item'])->whereNumber('item');
        $router->get('api/zz-kinds/{item}', [ConstraintController::class, 'item'])->whereIn('item', ['draft', 'published']);
        $router->get('api/zz-slugs/{item}', [ConstraintController::class, 'item'])->where('item', '[a-z0-9]+(?:-[a-z0-9]+)*');
        $router->get('api/zz-codes/{code}', [ConstraintController::class, 'code']);
        $router->get('api/zz-digits/{item}', [ConstraintController::class, 'item'])->where('item', '\d+');
    });

    assertGolden('route-constraints.uir.json', (new UirEmitter)->emit($result->document));
});
