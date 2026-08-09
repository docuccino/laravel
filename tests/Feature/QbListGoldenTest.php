<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\QbListController;
use Workbench\App\Support\ListQueryBuilder;

/**
 * The flagship Query Builder list-endpoint golden — closes the "no QB 200 golden" hole. A QB SUBCLASS
 * ({@see ListQueryBuilder}) is filtered/sorted and paginated through a CUSTOM
 * terminal (`paginateList`, declared in `integrations.query_builder.pagination_terminals`) and returns
 * a resource collection. The golden pins the whole shape end-to-end: the recovered filter/sort params,
 * the custom terminal's page/per_page params, the strict-mode 400, AND the previously-missing QB 200 —
 * the `{data, links, meta}` collection envelope the custom terminal triggers (arch PIN 3 / D3).
 *
 * Registered ad-hoc (not in the default route set) so no other committed golden churns.
 */
it('emits the flagship QB list document byte-identical to its committed golden', function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    /** @var Router $router */
    $router = app('router');
    $router->get('api/qb-list', [QbListController::class, 'index']);

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $raw['info'] = ['title' => 'QB List API', 'version' => '1.0.0'];
    $raw['routes'] = ['include' => ['api/qb-list']];
    // Declare the QB subclass's custom paginating terminal so it is recovered for BOTH the page
    // parameters (QB integration) and the {data,links,meta} envelope (resource integration, D3).
    $raw['integrations'] = ['query_builder' => ['pagination_terminals' => ['paginateList']]];

    $config = app(DocumentConfigFactory::class)->make('qb-list', $raw, 'skeleton');
    $emitted = (new UirEmitter)->emit(app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document);

    $path = dirname(__DIR__).'/Fixtures/golden/workbench-qb-list.uir.json';
    if (getenv('DOCUCCINO_UPDATE_GOLDEN') === '1') {
        file_put_contents($path, $emitted);
    }

    expect($emitted)->toBe(file_get_contents($path));

    // Spot-checks over the emitted bytes: the QB 200 envelope + the custom-terminal page params land.
    $document = json_decode($emitted, true, flags: JSON_THROW_ON_ERROR);
    $op = $document['paths']['/api/qb-list']['get'];
    $paramNames = array_map(static fn (array $p): string => $p['name'], $op['parameters']);

    expect($op['responses'])->toHaveKeys(['200', '400'])
        ->and($op['responses']['200']['content']['application/json']['schema']['properties'] ?? [])->toHaveKeys(['data', 'links', 'meta'])
        ->and($paramNames)->toContain('filter[name]')
        ->and($paramNames)->toContain('page')
        ->and($paramNames)->toContain('per_page');
});
