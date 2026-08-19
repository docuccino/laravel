<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\FragmentStore;
use Docuccino\Laravel\Watch\WatchSet;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;
use Workbench\App\Http\Controllers\WatchedController;

/**
 * The invariant watch mode is most likely to break: it rebuilds incrementally forever, so the Nth
 * rebuild after an edit has to be the document a cold build would produce — bytes AND diagnostics.
 *
 * {@see WarmColdEqualityTest} covers a route SET changing between builds. This covers the other half,
 * and the one a watch session actually spends its life on: the same routes, with a file one of them
 * depends on rewritten underneath. It also pins the seam the two halves share — that the file is in
 * the watch set precisely because a fragment recorded it.
 */
it('rebuilds an edited file into the same document a cold build would produce', function (): void {
    $description = base_path('docuccino-watched.md');
    file_put_contents($description, "# Watched\n\nFirst prose.\n");

    $routes = static function (Router $router): void {
        $router->get('api/watched', [WatchedController::class, 'index']);
        // A second route that never changes, so the rebuild really is incremental.
        $router->get('api/forms', [FormController::class, 'index']);
    };

    $warmDir = fragmentCacheDir('watch-equality');
    $coldDir = null;

    try {
        localityBuild($routes);

        // The edited file is watched BECAUSE the fragment recorded it — the whole claim of the feature.
        $watched = new WatchSet(app(DocumentBuilder::class), new FragmentStore(true, $warmDir), base_path());
        expect($watched->operationFiles())->toContain($description);

        file_put_contents($description, "# Watched\n\nSecond prose, longer than the first.\n");

        $warm = localityBuild($routes, engine: null, bound: $warmEngine);

        $coldDir = fragmentCacheDir('watch-equality');
        $cold = localityBuild($routes, engine: null, bound: $coldEngine);

        expect((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
            ->and(diagnosticRecords($warm->diagnostics))->toBe(diagnosticRecords($cold->diagnostics))
            // …documenting the NEW prose, so the rebuild noticed rather than agreeing on a stale answer.
            ->and($warm->document->toArray()['paths']['/api/watched']['get']['description'] ?? null)
            ->toBe("# Watched\n\nSecond prose, longer than the first.")
            // …and the untouched route stayed warm, so this was an incremental rebuild and not a cold one.
            ->and($warmEngine->analyzeCount)->toBeLessThan($coldEngine->analyzeCount);
    } finally {
        @unlink($description);
        removeFragmentCacheDir($warmDir);
        if ($coldDir !== null) {
            removeFragmentCacheDir($coldDir);
        }
    }
});
