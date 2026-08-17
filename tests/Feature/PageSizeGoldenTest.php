<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\PageSizeTraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\PagedListController;
use Workbench\App\Support\ListPageSize;

/**
 * The page-size golden: four list endpoints that page the same collection through the same helper class,
 * and the document each of them earns. Two of the helper's methods answer with the value they read off the
 * request and two answer with a literal of their own, so the emitted bytes are where the rule lands — a key
 * beside `page` where the read IS the size, and no second parameter at all where the request only chose
 * between sizes.
 *
 * Registered ad-hoc (not in the default route set) so no other committed golden churns.
 */
it('emits the page-size document byte-identical to its committed golden', function (): void {
    $location = new SourceLocation('');
    $collection = new ClassT('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection', [
        new ClassT('Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\ArticleResource'),
    ]);

    // One helper class, one method per endpoint — the size argument is the only thing that differs.
    $helper = '\\'.ListPageSize::class.'::';
    $actions = [
        // The shared clamp: `max(1, min($request->integer('per_page', $default), $max))`. The fallback is
        // the helper's own parameter, so the key is published without a default.
        'clamped' => 'clamp($request)',
        // The read named in a local and clamped after: the key AND the literal fallback it was written with.
        'limited' => 'limit($request)',
        // `match ($request->input('preset'))` — the key picks the arm; every arm holds a literal.
        'preset' => 'preset($request)',
        // A read inside a closure the helper never calls.
        'lazy' => 'lazy($request)',
    ];

    $traces = [];
    $analyses = [];
    /** @var Router $router */
    $router = app('router');
    foreach ($actions as $method => $size) {
        $symbol = PagedListController::class.'::'.$method;
        $traces[$symbol] = PageSizeTraceScript::forChain(
            '$q->paginate('.$helper.$size.')',
            [ListPageSize::class],
            $method.'.php',
        );
        $analyses[$symbol] = new ActionAnalysis(returns: [new ReturnSite($collection, $location)]);
        $router->get('api/paged/'.$method, [PagedListController::class, $method]);
    }

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: $analyses,
        traceOverrides: $traces,
    ));

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $raw['info'] = ['title' => 'Paged List API', 'version' => '1.0.0'];
    $raw['routes'] = ['include' => ['api/paged/*']];

    $config = app(DocumentConfigFactory::class)->make('page-size', $raw, 'skeleton');
    $emitted = (new UirEmitter)->emit(app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document);

    $path = dirname(__DIR__).'/Fixtures/golden/workbench-page-size.uir.json';
    if (getenv('DOCUCCINO_UPDATE_GOLDEN') === '1') {
        file_put_contents($path, $emitted);
    }

    expect($emitted)->toBe(file_get_contents($path));

    // Spot-checks over the emitted bytes, so a reader of this test sees what the golden locks.
    $document = json_decode($emitted, true, flags: JSON_THROW_ON_ERROR);
    $parameters = static function (string $method) use ($document): array {
        $byName = [];
        foreach ($document['paths']['/api/paged/'.$method]['get']['parameters'] ?? [] as $parameter) {
            $byName[$parameter['name']] = $parameter;
        }

        return $byName;
    };

    /** The published schema without the provenance the UIR carries beside it. */
    $schema = static fn (array $parameter): array => array_diff_key($parameter['schema'] ?? [], ['x-docuccino' => null]);

    $clamped = $parameters('clamped');
    expect(array_keys($clamped))->toEqualCanonicalizing(['page', 'per_page'])
        ->and($schema($clamped['per_page']))->toBe(['type' => 'integer'])
        ->and($clamped['per_page']['description'])->toBe('Number of items per page.')
        // The key is whatever the code reads, and the default only where the read was written with one.
        ->and($schema($parameters('limited')['limit'] ?? []))->toBe(['type' => 'integer', 'default' => 15])
        // Both refusals: the endpoint pages by a literal, so `page` is the only selector documented.
        ->and(array_keys($parameters('preset')))->toBe(['page'])
        ->and(array_keys($parameters('lazy')))->toBe(['page']);
});
