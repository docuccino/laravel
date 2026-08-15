<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Merchant;
use Docuccino\Laravel\Tests\Fixtures\RouteBindings\BindingController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * `Route::get('posts/{post:slug}', …)` is ordinary Laravel, and Laravel parses the `:slug` OUT of
 * `uri()` — so by the time anything downstream sees the route it reads `{post}`, and the parameter was
 * typed from the model's default route key: an integer `id` for an endpoint that accepts a slug. A
 * generated client would send a number where a string belongs, which this project treats as worse than
 * saying nothing.
 *
 * So the column the route names is what types the parameter, and when nothing can type that column the
 * answer is the plain string every untyped segment gets — never the key's schema, which would be a
 * confident wrong one — plus a diagnostic naming the column that went untyped.
 */
beforeEach(function (): void {
    $this->boundDocument = static function (callable $routes, ?callable $engine = null): array {
        $routes(app('router'));
        app()->instance(TypeEngine::class, ($engine ?? static fn (): TypeEngine => WorkbenchEngine::make(classOverrides: [
            Merchant::class => new ClassMetadata(Merchant::class, [
                new PropertyMetadata('id', ScalarT::int()),
                new PropertyMetadata('name', ScalarT::string()),
            ]),
        ]))());

        $result = generateDocument();

        return [$result->document->toArray(), $result->diagnostics];
    };
});

it('types a parameter from the column its route names, not from the route key', function (): void {
    [$document] = ($this->boundDocument)(static function (Router $router): void {
        $router->get('api/zz-merchants/{merchant:name}', [BindingController::class, 'merchant']);
        // The same model and the same action, bound the ordinary way — the control.
        $router->get('api/zz-merchant-keys/{merchant}', [BindingController::class, 'merchant']);
    });

    $named = pathParameter($document['paths']['/api/zz-merchants/{merchant}']['get'], 'merchant');
    $key = pathParameter($document['paths']['/api/zz-merchant-keys/{merchant}']['get'], 'merchant');

    expect($named)->not->toBeNull()
        ->and($named)->toHaveKey('schema')
        ->and($named['schema']['type'])->toBe('string')
        // …and the control still answers with the key, so the difference is the column and nothing else.
        ->and($key)->not->toBeNull()
        ->and($key)->toHaveKey('schema')
        ->and($key['schema']['type'])->toBe('integer');
});

it('keeps the key column answering with the key schema, format and all', function (): void {
    [$document] = ($this->boundDocument)(static function (Router $router): void {
        $router->get('api/zz-vaults/{vault:id}', [BindingController::class, 'vault']);
    });

    $parameter = pathParameter($document['paths']['/api/zz-vaults/{vault}']['get'], 'vault');

    expect($parameter)->not->toBeNull()
        ->and($parameter)->toHaveKey('schema')
        ->and($parameter['schema'])->toHaveKey('format')
        ->and($parameter['schema']['type'])->toBe('string')
        ->and($parameter['schema']['format'])->toBe('uuid');
});

it('documents a column it cannot type as a plain string, and says which one', function (callable $routes, string $path, string $parameterName, string $expectedInMessage, ?callable $engine): void {
    [$document, $diagnostics] = ($this->boundDocument)($routes, $engine);

    $parameter = pathParameter($document['paths'][$path]['get'], $parameterName);
    $reports = diagnosticsCoded($diagnostics, 'route-binding.column-untyped');

    expect($parameter)->not->toBeNull()
        ->and($parameter)->toHaveKey('schema')
        // The honest degradation is the one an untyped segment already gets — never the route key's.
        ->and($parameter['schema']['type'])->toBe('string')
        ->and($parameter['schema'])->not->toHaveKey('format')
        // …and it says so: a string nothing inferred is recorded as the fallback that it is.
        ->and($parameter['schema']['x-docuccino']['provenance'][0]['producer'])->toBe('fallback')
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->severity->value)->toBe('info')
        ->and($reports[0]->message)->toContain($expectedInMessage)
        ->and($reports[0]->routeSignature)->toBe('GET '.$path);
})->with([

    // A column the model evidences nowhere: no `@property` tag, no cast.
    'a column no source mentions' => [
        static fn (Router $r) => $r->get('api/zz-blanks/{blank:slug}', [BindingController::class, 'blank']),
        '/api/zz-blanks/{blank}',
        'blank',
        'blank:slug',
        null,
    ],

    // The engine-less install. It types nothing, so the column is a string and the reader is told why
    // rather than handed the key's integer.
    'no analyser installed' => [
        static fn (Router $r) => $r->get('api/zz-merchants/{merchant:name}', [BindingController::class, 'merchant']),
        '/api/zz-merchants/{merchant}',
        'merchant',
        'merchant:name',
        static fn (): TypeEngine => new NullTypeEngine,
    ],

    // Implicit binding works on anything UrlRoutable, and nothing off an Eloquent model can type it.
    'a binding on a class that is not a model' => [
        static fn (Router $r) => $r->get('api/zz-tickets/{ticket:reference}', [BindingController::class, 'ticket']),
        '/api/zz-tickets/{ticket}',
        'ticket',
        'ticket:reference',
        null,
    ],
]);

it('leaves an ordinary bound parameter untouched and unreported', function (): void {
    // The negative half. A route naming no column must emit exactly what it emitted before this
    // existed, and must not collect a diagnostic about a column it never named.
    [$document, $diagnostics] = ($this->boundDocument)(static function (Router $router): void {
        $router->get('api/zz-blanks/{blank}', [BindingController::class, 'blank']);
    });

    $parameter = pathParameter($document['paths']['/api/zz-blanks/{blank}']['get'], 'blank');

    expect($parameter)->not->toBeNull()
        ->and($parameter)->toHaveKey('schema')
        ->and($parameter['schema']['type'])->toBe('integer')
        ->and($parameter['schema']['x-docuccino']['provenance'][0]['producer'])->toBe('inference')
        ->and(diagnosticsCoded($diagnostics, 'route-binding.column-untyped'))->toBeEmpty();
});

it('says nothing about a column named on a parameter no action binds', function (): void {
    // `{blank:slug}` with no type-hint is not a binding at all — Laravel hands the action a raw string.
    // There is no model to type against, so the plain string is already the whole truth and a
    // diagnostic would be noise.
    [$document, $diagnostics] = ($this->boundDocument)(static function (Router $router): void {
        $router->get('api/zz-loose/{blank:slug}', static fn (string $blank): array => []);
    });

    $parameter = pathParameter($document['paths']['/api/zz-loose/{blank}']['get'], 'blank');

    expect($parameter)->not->toBeNull()
        ->and($parameter)->toHaveKey('schema')
        ->and($parameter['schema']['type'])->toBe('string')
        ->and(diagnosticsCoded($diagnostics, 'route-binding.column-untyped'))->toBeEmpty();
});

it('replays the untyped-column report on a warm build', function (): void {
    // The report is the route's, so it rides its fragment. A warm hit reassembles the operation without
    // running a single extension — and a build that quietly loses the report is one that looks more
    // confident than the cold build it is meant to be identical to.
    $dir = sys_get_temp_dir().'/docuccino-binding-warm-'.uniqid('', true);
    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $dir);

    try {
        [, $cold] = ($this->boundDocument)(static function (Router $router): void {
            $router->get('api/zz-warm/{blank:slug}', [BindingController::class, 'blank']);
        });

        expect(glob($dir.'/*.json') ?: [])->not->toBeEmpty();

        [$document, $warm] = ($this->boundDocument)(static function (): void {});

        expect(pathParameter($document['paths']['/api/zz-warm/{blank}']['get'], 'blank'))->not->toBeNull()
            ->and(diagnosticRecords(diagnosticsCoded($warm, 'route-binding.column-untyped')))
            ->toBe(diagnosticRecords(diagnosticsCoded($cold, 'route-binding.column-untyped')))
            ->not->toBeEmpty();
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        @unlink($dir.'/.gitignore');
        @rmdir($dir);
    }
});

it('keys the fragment on every file the column type was read from', function (): void {
    // The column type comes off files the engine names — the model's, and its parents' and traits'. Any
    // of them can be edited without the route moving an inch, so all of them have to reach the key or a
    // retyped column keeps answering with the type it used to have.
    $dir = sys_get_temp_dir().'/docuccino-binding-deps-'.uniqid('', true);
    $parentFile = $dir.'-parent.php';
    file_put_contents($parentFile, "<?php\n");
    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $dir);

    try {
        ($this->boundDocument)(
            static function (Router $router): void {
                $router->get('api/zz-deps/{merchant:name}', [BindingController::class, 'merchant']);
            },
            static fn (): TypeEngine => WorkbenchEngine::make(classOverrides: [
                Merchant::class => new ClassMetadata(
                    Merchant::class,
                    [new PropertyMetadata('name', ScalarT::string())],
                    dependencyFiles: [$parentFile],
                ),
            ]),
        );

        $entries = glob($dir.'/*.json') ?: [];
        expect($entries)->not->toBeEmpty();

        $files = [];
        foreach ($entries as $entry) {
            /** @var array{dependencies?: list<array{file?: string}>} $decoded */
            $decoded = json_decode((string) file_get_contents($entry), true, flags: JSON_THROW_ON_ERROR);
            $files = [...$files, ...array_column($decoded['dependencies'] ?? [], 'file')];
        }

        expect($files)->toContain($parentFile);
    } finally {
        @unlink($parentFile);
        array_map('unlink', glob($dir.'/*') ?: []);
        @unlink($dir.'/.gitignore');
        @rmdir($dir);
    }
});
