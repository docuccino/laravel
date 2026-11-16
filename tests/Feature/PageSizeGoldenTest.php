<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\PageSizeTraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\PagedListController;
use Workbench\App\Http\Requests\PageSizeBoundsRequest;
use Workbench\App\Support\ListPageSize;

/**
 * The page-size golden: list endpoints that page the same collection, four through the same helper class
 * and two under a FormRequest bounding the size, and the document each of them earns. Two of the helper's methods answer with the value they read off the
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

    // Two endpoints reading the key directly under a FormRequest bounding it at 1..20: the fallback past
    // the bound and the one inside it.
    $bounds = new ArrayShapeT([new ArrayShapeField('per_page', new LiteralT('integer|min:1|max:20'))]);
    $analyses[PageSizeBoundsRequest::class.'::rules'] = new ActionAnalysis(returns: [new ReturnSite($bounds, $location)]);
    foreach (['overBound' => 50, 'withinBound' => 15] as $method => $fallback) {
        $symbol = PagedListController::class.'::'.$method;
        $traces[$symbol] = PageSizeTraceScript::forChain('$q->paginate($request->integer(\'per_page\', '.$fallback.'))', [], $method.'.php');
        $analyses[$symbol] = new ActionAnalysis(returns: [new ReturnSite($collection, $location)]);
        $router->get('api/paged/'.$method, [PagedListController::class, $method]);
    }

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: $analyses,
        traceOverrides: $traces,
    ));

    /** @var array<string, mixed> $raw */
    $raw = documentSettings();
    $raw['info'] = ['title' => 'Paged List API', 'version' => '1.0.0'];
    $raw['routes'] = ['include' => ['api/paged/*']];

    $config = app(DocumentConfigFactory::class)->make('page-size', $raw, 'skeleton');
    $emitted = (new UirEmitter)->emit(app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document);

    assertGolden('workbench-page-size.uir.json', $emitted);

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
        ->and(array_keys($parameters('lazy')))->toBe(['page'])
        // Under validated bounds a fallback past them is what an absent key gets, so it is stated in prose
        // and never published as a default the schema rejects; one within them stays the default.
        ->and($schema($parameters('overBound')['per_page']))->not->toHaveKey('default')
        ->and($parameters('overBound')['per_page']['description'])->toBe("Number of items per page.\n\nDefaults to `50`.")
        ->and($schema($parameters('withinBound')['per_page'])['default'] ?? null)->toBe(15);
});
