<?php

declare(strict_types=1);

use Docuccino\Core\Diff\ChangeKind;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Fixtures\RouteConstraints\ConstraintController;
use Docuccino\Laravel\Tests\Fixtures\RouteConstraints\DeclaredIdController;
use Docuccino\Laravel\Tests\Fixtures\RouteConstraints\OptionalSegmentController;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/**
 * A route with optional segments (`/posts/{page?}`) answers one URL per form — `/posts/{page}` and
 * `/posts` — and OpenAPI has no optional path parameter: every version requires `required: true` of one,
 * since the path it sits in is only ever that path with the segment present. So each form is an operation
 * of its own, and a client, a mock server or a contract test can call every URL the router serves.
 *
 * The full form keeps the identity the route always had, so an existing document diffs as that
 * operation unchanged and the short forms added. Where leaving the last segment off sends a value, that
 * value is the segment's `default`, since sending it answers exactly as the shorter form does.
 */
beforeEach(function (): void {
    // The framework is the oracle for what a URL answers, so a row registers its routes afresh to ask it.
    $this->serve = static function (callable $routes): void {
        app('router')->setRoutes(new RouteCollection);
        $routes(app('router'));
    };
});

it('publishes every URL form of the route as an operation of its own', function (): void {
    $routes = static function (Router $router): void {
        $router->get('api/zz-archive/{year?}/{month?}', [OptionalSegmentController::class, 'archive'])->name('archive.show');
    };
    $result = localityBuild($routes);
    $paths = $result->document->toArray()['paths'];

    expect(array_keys($paths))->toBe(['/api/zz-archive', '/api/zz-archive/{year}', '/api/zz-archive/{year}/{month}'])
        ->and(array_column($paths['/api/zz-archive/{year}/{month}']['get']['parameters'], 'required', 'name'))->toBe(['year' => true, 'month' => true])
        ->and(array_column($paths['/api/zz-archive/{year}']['get']['parameters'], 'required', 'name'))->toBe(['year' => true])
        ->and($paths['/api/zz-archive']['get'])->not->toHaveKey('parameters')
        // Each form names a function of its own, a function of the segments it leaves off.
        ->and(array_map(static fn (array $item): ?string => $item['get']['operationId'] ?? null, $paths))->toBe([
            '/api/zz-archive' => 'archive.show.without-year-month',
            '/api/zz-archive/{year}' => 'archive.show.without-month',
            '/api/zz-archive/{year}/{month}' => 'archive.show',
        ])
        ->and(diagnosticsCoded($result->diagnostics, 'route.duplicate-operation-id'))->toBe([]);

    // Every version's meta-schema holds the document to one required parameter per template name.
    foreach (['openapi-3.0', 'openapi-3.1', 'openapi-3.2'] as $format) {
        $emitted = json_decode(Formats::emit($format, $result->document, new EmitOptions)->output, flags: JSON_THROW_ON_ERROR);
        expect(OpenApiMetaSchema::findings($format, $emitted))->toBe([]);
    }

    // …and every path it publishes is one the router serves with this action.
    ($this->serve)($routes);
    expect($this->getJson('/api/zz-archive')->json())->toBe(['year' => null, 'month' => '01'])
        ->and($this->getJson('/api/zz-archive/2024')->json())->toBe(['year' => '2024', 'month' => '01'])
        ->and($this->getJson('/api/zz-archive/2024/05')->json())->toBe(['year' => '2024', 'month' => '05']);
});

it('mints each unnamed form its id from its own path', function (): void {
    $paths = localityBuild(static function (Router $router): void {
        $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'page']);
    })->document->toArray()['paths'];

    expect($paths['/api/zz-pages']['get']['operationId'])->toBe('get.api.zz-pages')
        ->and($paths['/api/zz-pages/{page}']['get']['operationId'])->toBe('get.api.zz-pages.@page');
});

it('names every form alike whichever site sets the operationId', function (callable $register, ?string $strategy, array $expected): void {
    if ($strategy !== null) {
        setBuild('documents.default.representation.operation_id', $strategy);
    }

    $paths = localityBuild(static function (Router $router) use ($register): void {
        $register($router);
    })->document->toArray()['paths'];

    // The rule, stated here rather than asked of the code: an id every form shares gains the segments a
    // form leaves off; an id minted from the path needs nothing, since each form's path mints its own.
    expect(array_map(static fn (array $item): ?string => $item['get']['operationId'] ?? null, $paths))->toBe($expected);
})->with([
    'the route name' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'page'])->name('pages.show'), null, [
        '/api/zz-pages' => 'pages.show.without-page', '/api/zz-pages/{page}' => 'pages.show',
    ]],
    'the controller method' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'page']), 'controller-method', [
        '/api/zz-pages' => 'OptionalSegmentController@page.without-page', '/api/zz-pages/{page}' => 'OptionalSegmentController@page',
    ]],
    'a declared #[OperationId]' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [DeclaredIdController::class, 'show'])->name('pages.show'), null, [
        '/api/zz-pages' => 'pages.fetch.without-page', '/api/zz-pages/{page}' => 'pages.fetch',
    ]],
    'the skeleton a failed build publishes' => [static function (Router $router): void {
        $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'page'])->name('pages.show');
        Docuccino::extend(new class implements OperationExtension
        {
            public function phase(): OperationPhase
            {
                return OperationPhase::Finalize;
            }

            public function handle(OperationDraft $operation, RouteContext $context): void
            {
                throw new RuntimeException('boom');
            }
        });
    }, null, ['/api/zz-pages' => 'pages.show.without-page', '/api/zz-pages/{page}' => 'pages.show']],
    'a minted id' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'page']), null, [
        '/api/zz-pages' => 'get.api.zz-pages', '/api/zz-pages/{page}' => 'get.api.zz-pages.@page',
    ]],
]);

it('keeps the full form\'s identity, so an existing document diffs as unchanged plus added', function (): void {
    // The same route with its segment required is what a document before the short forms described.
    $required = localityBuild(static function (Router $router): void {
        $router->get('api/zz-pages/{page}', [OptionalSegmentController::class, 'page'])->name('pages.show');
    })->document;
    $optional = localityBuild(static function (Router $router): void {
        $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'page'])->name('pages.show');
    })->document;

    $changes = (new DocumentDiffer)->diff($required, $optional)->changes;

    expect(array_map(static fn ($change): array => [$change->kind, $change->path], $changes))->toBe([[ChangeKind::Added, 'GET /api/zz-pages']])
        ->and($changes[0]->breaking)->toBeFalse();
});

it('publishes the value leaving the last segment off sends, as the framework sends it', function (callable $register, string $expected): void {
    $routes = static function (Router $router) use ($register): void {
        $register($router);
    };
    $parameter = pathParameter(localityBuild($routes)->document->toArray()['paths']['/api/zz-pages/{page}']['get'], 'page');

    ($this->serve)($routes);

    expect($parameter['schema']['default'] ?? null)->toBe($expected)
        ->and($parameter['description'])->toBe(sprintf('Leaving it off is the same as sending `%s`.', $expected))
        ->and($this->getJson('/api/zz-pages')->json('page'))->toEqual($this->getJson('/api/zz-pages/'.$expected)->json('page'));
})->with([
    'the route\'s default' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'page'])->defaults('page', 'latest'), 'latest'],
    'the action parameter\'s default' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'first']), 'first'],
    // The route's default is handed to the action ahead of the action's own.
    'the route\'s default over the action\'s' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'first'])->defaults('page', 'second'), 'second'],
    // A null default is none: the dispatcher drops null parameters and the action's default fills it.
    'a null route default' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'first'])->defaults('page', null), 'first'],
    // An integer is sent as its digits; an injected dependency ahead of it does not shift which one fills it.
    'an integer after an injected dependency' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'numbered']), '3'],
    'a closure\'s default' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', static fn (string $page = 'recent'): array => ['page' => $page]), 'recent'],
    'a default the constraint accepts' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'first'])->whereIn('page', ['first', 'last']), 'first'],
]);

it('publishes no default the segment could not send', function (callable $register): void {
    $parameter = pathParameter(localityBuild(static function (Router $router) use ($register): void {
        $register($router);
    })->document->toArray()['paths']['/api/zz-pages/{page}']['get'], 'page');

    // A value outside the route's constraint is a 404 when sent, and one with no spelling as a segment
    // cannot be sent at all: a client filling either in would not get what the short form gets.
    expect($parameter['schema'])->not->toHaveKey('default')
        ->and($parameter)->not->toHaveKey('description');
})->with([
    'none at all' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'page'])],
    'outside the constraint' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'first'])->whereNumber('page')],
    'a list' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'listed'])],
    // The route's default is what the action receives, so the action's own never answers beside it.
    'a route default with no spelling' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'first'])->defaults('page', ['a'])],
    'an empty string' => [static fn (Router $router) => $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'first'])->defaults('page', '')],
]);

it('states a default only on the segment whose absence is the next shorter form', function (): void {
    $routes = static function (Router $router): void {
        $router->get('api/zz-archive/{year?}/{month?}', [OptionalSegmentController::class, 'archive'])->defaults('year', '2020');
    };
    $paths = localityBuild($routes)->document->toArray()['paths'];

    // Leaving `year` off leaves `month` off too, so sending `2020` beside a month is no shorter form; in
    // the form that ends at `year`, it is exactly the form without it.
    expect(pathParameter($paths['/api/zz-archive/{year}/{month}']['get'], 'year')['schema'])->not->toHaveKey('default')
        ->and(pathParameter($paths['/api/zz-archive/{year}/{month}']['get'], 'month')['schema']['default'] ?? null)->toBe('01')
        ->and(pathParameter($paths['/api/zz-archive/{year}']['get'], 'year')['schema']['default'] ?? null)->toBe('2020');

    ($this->serve)($routes);
    expect($this->getJson('/api/zz-archive')->json())->toBe($this->getJson('/api/zz-archive/2020')->json())
        ->and($this->getJson('/api/zz-archive/2024')->json())->toBe($this->getJson('/api/zz-archive/2024/01')->json());
});

it('leaves a declared type its own shape, and says nothing of it on the form without the segment', function (): void {
    $result = localityBuild(static function (Router $router): void {
        $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'declared']);
    });
    $paths = $result->document->toArray()['paths'];
    $parameter = pathParameter($paths['/api/zz-pages/{page}']['get'], 'page');

    // `default: "1"` beside `type: integer` would contradict the type the declaration states, and the
    // declaration names a segment the short form leaves off rather than one missing from the route.
    expect($parameter['schema']['type'])->toBe('integer')
        ->and($parameter['schema'])->not->toHaveKey('default')
        ->and($parameter['description'])->toBe('The page.')
        ->and($paths['/api/zz-pages']['get'])->not->toHaveKey('parameters')
        ->and(diagnosticsCoded($result->diagnostics, 'attribute.path-parameter-unmatched'))->toBe([]);
});

it('publishes one form for a segment the router requires, whatever its marker says', function (): void {
    $paths = localityBuild(static function (Router $router): void {
        // A `{page?}` followed by more path is required after all: the router cannot tell where it ended.
        $router->get('api/zz-pages/{page?}/items', [OptionalSegmentController::class, 'first']);
    })->document->toArray()['paths'];

    expect(array_keys($paths))->toBe(['/api/zz-pages/{page}/items'])
        ->and(pathParameter($paths['/api/zz-pages/{page}/items']['get'], 'page')['required'])->toBeTrue();
});

it('publishes the route the router serves a short form\'s URL with', function (bool $explicitFirst): void {
    $routes = static function (Router $router) use ($explicitFirst): void {
        $explicit = static fn () => $router->get('api/zz-pages', [ConstraintController::class, 'hosted'])->name('pages.all');
        if ($explicitFirst) {
            $explicit();
        }
        $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'first'])->name('pages.show');
        if (! $explicitFirst) {
            $explicit();
        }
    };
    $result = localityBuild($routes);
    $operation = $result->document->toArray()['paths']['/api/zz-pages']['get'];

    // The router serves a request with the first route registered that matches it.
    ($this->serve)($routes);
    $served = $this->getJson('/api/zz-pages')->json();

    $shadowed = diagnosticsCoded($result->diagnostics, 'route.shadowed');

    expect($operation['operationId'])->toBe($explicitFirst ? 'pages.all' : 'pages.show.without-page')
        ->and($served)->toBe($explicitFirst ? [] : ['page' => 'first'])
        ->and(diagnosticsCoded($result->diagnostics, 'route.operation-collision'))->toBe([])
        // A route no request reaches is the author's to remove, and only that case is reported.
        ->and($shadowed)->toHaveCount($explicitFirst ? 0 : 1)
        ->and($explicitFirst ? null : $shadowed[0]->routeSignature)->toBe($explicitFirst ? null : 'GET /api/zz-pages');
})->with(['the explicit route registered first' => [true], 'the explicit route registered after' => [false]]);

it('keeps a route the router reaches, whatever another\'s short form shares its path with', function (): void {
    $routes = static function (Router $router): void {
        $router->get('api/zz-reports/{year}/{month?}', [OptionalSegmentController::class, 'archive'])->whereNumber(['year', 'month'])->name('reports.monthly');
        $router->get('api/zz-reports/{year}', [OptionalSegmentController::class, 'page'])->where('year', 'summary|latest')->name('reports.named');
    };
    $result = localityBuild($routes);
    $paths = $result->document->toArray()['paths'];

    // Both answer requests, so neither is unreachable; OpenAPI has room for one operation on the path,
    // and the route's own path is the one published, with the short form it displaced reported.
    ($this->serve)($routes);
    $collisions = diagnosticsCoded($result->diagnostics, 'route.operation-collision');

    expect($this->getJson('/api/zz-reports/latest')->json())->toBe(['page' => 'latest'])
        ->and($this->getJson('/api/zz-reports/2024')->json())->toBe(['year' => '2024', 'month' => '01'])
        ->and(diagnosticsCoded($result->diagnostics, 'route.shadowed'))->toBe([])
        ->and($paths['/api/zz-reports/{year}']['get']['operationId'])->toBe('reports.named')
        ->and($collisions)->toHaveCount(1)
        ->and($collisions[0]->routeSignature)->toBe('GET /api/zz-reports/{year}/{month?}')
        ->and($collisions[0]->message)->toContain('without {month}');
});

it('publishes one of two paths differing only by names, the one the router serves', function (): void {
    $routes = static function (Router $router): void {
        $router->get('api/zz-users/{id}/{tab?}', [OptionalSegmentController::class, 'archive'])->name('users.tab');
        $router->get('api/zz-users/{user}', [OptionalSegmentController::class, 'page'])->name('users.show');
    };
    $result = localityBuild($routes);
    $paths = array_keys($result->document->toArray()['paths']);

    ($this->serve)($routes);

    // OpenAPI: templated paths with the same hierarchy but different names MUST NOT coexist.
    expect($this->getJson('/api/zz-users/7')->json())->toBe(['year' => '7', 'month' => '01'])
        ->and($paths)->toBe(['/api/zz-users/{id}', '/api/zz-users/{id}/{tab}'])
        ->and(array_map(static fn ($d): ?string => $d->routeSignature, diagnosticsCoded($result->diagnostics, 'route.shadowed')))->toBe(['GET /api/zz-users/{user}']);
});

it('reports what the forms share once, against the full form', function (): void {
    $result = localityBuild(static function (Router $router): void {
        $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'undescribed']);
    });

    expect(diagnosticsCoded($result->diagnostics, 'description-file.missing'))->toHaveCount(1)
        ->and($result->document->toArray()['paths'])->toHaveKeys(['/api/zz-pages', '/api/zz-pages/{page}']);
});

it('keeps a workflow step on the full form alone', function (): void {
    $result = localityBuild(static function (Router $router): void {
        $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'stepped']);
    });
    $document = $result->document->toArray();
    $steps = $document['x-docuccino']['workflows'][0]['steps'] ?? [];

    // A step's declaration binds the full form's parameters, so the step is that operation and no other.
    expect(array_column($steps, 'operation'))->toBe([$document['paths']['/api/zz-pages/{page}']['get']['x-docuccino']['id']])
        ->and(array_filter($result->diagnostics, static fn ($d): bool => str_starts_with($d->code, 'workflow.')))->toBe([]);
});

it('builds the same document from a cached router as from the routes it was cached from', function (): void {
    $routes = static function (Router $router): void {
        $router->get('api/zz-users/{id}', [ConstraintController::class, 'item'])->name('users.show');
        $router->get('api/zz-users/me', [ConstraintController::class, 'hosted'])->name('users.me');
        $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'page']);
        $router->get('api/zz-a/{x}/{p?}', [OptionalSegmentController::class, 'archive'])->whereNumber('x')->name('a.numbered');
        $router->get('api/zz-a/{y}/{q?}', [OptionalSegmentController::class, 'archive'])->whereAlpha('y')->name('a.lettered');
    };
    $plain = localityBuild($routes);

    // `route:cache` hands the router a compiled collection, which builds a new route object whenever one
    // is asked for, and names every unnamed route with a random `generated::` name of its own.
    $cached = localityBuild(static function (Router $router) use ($routes): void {
        $routes($router);
        $router->setCompiledRoutes($router->getRoutes()->compile());
    });

    // What the router serves is the same routes either way, so the document is too.
    expect(app('router')->getRoutes())->toBeInstanceOf(CompiledRouteCollection::class)
        ->and((new UirEmitter)->emit($cached->document))->toBe((new UirEmitter)->emit($plain->document))
        ->and(diagnosticRecords($cached->diagnostics))->toBe(diagnosticRecords($plain->diagnostics))
        // …and it is the document a router serves, not two agreeing empty ones.
        ->and($plain->document->toArray()['paths'])->toHaveKeys(['/api/zz-pages', '/api/zz-pages/{page}', '/api/zz-a/{x}'])
        ->and($plain->document->toArray()['paths'])->not->toHaveKey('/api/zz-users/me')
        ->and($plain->document->toArray()['paths']['/api/zz-pages']['get']['operationId'])->toBe('get.api.zz-pages')
        ->and(diagnosticsCoded($plain->diagnostics, 'route.shadowed'))->toHaveCount(1);
});

it('says which shorter paths still reach a route whose full form another answers for', function (): void {
    $routes = static function (Router $router): void {
        $router->get('api/zz-users/{id}', [ConstraintController::class, 'item'])->name('users.show');
        $router->get('api/zz-users/{user?}', [OptionalSegmentController::class, 'page'])->name('users.maybe');
    };
    $result = localityBuild($routes);
    $shadowed = diagnosticsCoded($result->diagnostics, 'route.shadowed');

    // The first route answers /api/zz-users/{id}, but only the second answers /api/zz-users.
    ($this->serve)($routes);
    expect($this->getJson('/api/zz-users')->json())->toBe(['page' => null])
        ->and($this->getJson('/api/zz-users/7')->json())->toBe([])
        ->and($result->document->toArray()['paths']['/api/zz-users']['get']['operationId'])->toBe('users.maybe.without-user')
        ->and($shadowed)->toHaveCount(1)
        ->and($shadowed[0]->message)->toBe('GET /api/zz-users/{user?} is reached for GET, HEAD only at /api/zz-users: the route /api/zz-users/{id} answers every URL it matches with all its segments given, and the router tries that route first.');
});

it('publishes the same short form of two whichever is registered first', function (bool $numberedFirst): void {
    $routes = static function (Router $router) use ($numberedFirst): void {
        $numbered = static fn () => $router->get('api/zz-a/{x}/{p?}', [OptionalSegmentController::class, 'archive'])->whereNumber('x')->name('a.numbered');
        $lettered = static fn () => $router->get('api/zz-a/{y}/{q?}', [OptionalSegmentController::class, 'archive'])->whereAlpha('y')->name('a.lettered');
        $numberedFirst ? [$numbered(), $lettered()] : [$lettered(), $numbered()];
    };
    $result = localityBuild($routes);
    $collisions = diagnosticsCoded($result->diagnostics, 'route.operation-collision');

    // Both short forms are served — neither route's segment accepts the other's values — and OpenAPI has
    // room for one, so the one published is a function of the two, first in path-template order.
    ($this->serve)($routes);
    expect($this->getJson('/api/zz-a/7')->json())->toBe(['year' => '7', 'month' => '01'])
        ->and($this->getJson('/api/zz-a/ada')->json())->toBe(['year' => 'ada', 'month' => '01'])
        ->and($result->document->toArray()['paths']['/api/zz-a/{x}']['get']['operationId'])->toBe('a.numbered.without-p')
        ->and(array_map(static fn ($d): ?string => $d->routeSignature, $collisions))->toBe(['GET /api/zz-a/{y}/{q?}', 'GET /api/zz-a/{y}/{q?}']);
})->with(['the numbered route registered first' => [true], 'the lettered route registered first' => [false]]);

it('builds every form warm exactly as cold', function (): void {
    $before = static function (Router $router): void {
        $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'page'])->defaults('page', 'latest');
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item']);
    };
    $after = static function (Router $router): void {
        $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'page'])->defaults('page', 'oldest');
        $router->get('api/zz-items/{item}', [ConstraintController::class, 'item']);
    };

    $warm = assertWarmEqualsCold($before, $after);
    $paths = $warm->document->toArray()['paths'];

    expect(pathParameter($paths['/api/zz-pages/{page}']['get'], 'page')['schema']['default'])->toBe('oldest')
        ->and($paths)->toHaveKey('/api/zz-pages');
});

it('publishes optional segments and constrained hosts byte-identical to its committed golden', function (): void {
    $result = localityBuild(static function (Router $router): void {
        $router->get('api/zz-pages/{page?}', [OptionalSegmentController::class, 'first'])->name('pages.show');
        $router->get('api/zz-archive/{year?}/{month?}', [OptionalSegmentController::class, 'archive']);
        $router->get('api/zz-latest/{page?}', [OptionalSegmentController::class, 'page'])->defaults('page', 'latest');
        $router->domain('{tenant}.example.com')->get('api/zz-tenants', [ConstraintController::class, 'hosted'])->whereIn('tenant', ['acme', 'globex']);
        $router->domain('{region}.example.com')->get('api/zz-regions', [ConstraintController::class, 'hosted'])->where('region', '[a-z]{2}-[0-9]');
    });

    assertGolden('route-segments.uir.json', (new UirEmitter)->emit($result->document));
});
