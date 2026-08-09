<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\DescribedController;
use Workbench\App\Http\Controllers\DescriptionEscapeController;

/**
 * #[DescriptionFromFile] path confinement (security L2): a traversal path is rejected with an error
 * diagnostic and reads nothing, while an in-tree file loads into the description.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

function describeRoutes(callable $routes): array
{
    /** @var Router $router */
    $router = app('router');
    $routes($router);

    $config = app(DocumentConfigFactory::class)->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    return (function () use ($config) {
        $result = app(DocumentGenerator::class)->generate($config, app(TypeEngine::class));

        return ['paths' => $result->document->toArray()['paths'], 'diagnostics' => $result->diagnostics];
    })();
}

it('rejects a #[DescriptionFromFile] path that escapes the base with an error diagnostic', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->get('api/escape', [DescriptionEscapeController::class, 'index']);
    });

    // No description was loaded from the out-of-tree file...
    expect($result['paths']['/api/escape']['get']['description'] ?? null)->toBeNull();

    // ...and the escape raised an error diagnostic.
    $escape = array_values(array_filter(
        $result['diagnostics'],
        static fn ($d): bool => $d->code === 'description-file.escapes-base-path' && $d->severity === Severity::Error,
    ));
    expect($escape)->not->toBeEmpty();
});

it('loads an in-tree #[DescriptionFromFile] into the operation description', function (): void {
    $absolute = base_path('docuccino-described.md');
    file_put_contents($absolute, "# Described\n\nBody prose.\n");

    $result = describeRoutes(function (Router $router): void {
        $router->get('api/described', [DescribedController::class, 'index']);
    });

    expect($result['paths']['/api/described']['get']['description'] ?? null)->toBe("# Described\n\nBody prose.")
        ->and($result['diagnostics'])->toBeArray();

    @unlink($absolute);
});
