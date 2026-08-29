<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Fixtures\Webhooks\Locality\Anchor\Anchor;
use Docuccino\Laravel\Tests\Fixtures\Webhooks\Locality\Neighbour\Neighbour;
use Docuccino\Laravel\Tests\Support\CollectedRouteNotes;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/*
 * Webhooks are document-level — no route reaches them — but the analysis behind one is as expensive
 * as a route's, so each travels as its own cached fragment. That makes it the fragment cache's
 * problem: a warm build must emit the same bytes AND report the same diagnostics as a cold one, and
 * the files the webhook was built from must be in its dependency manifest.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 2));
});

afterEach(function (): void {
    removeFragmentCacheDirs('warm');
    removeFragmentCacheDirs('cold');
});

it('emits and reports the same warm as cold, degradations included', function (): void {
    // The degraded directory on purpose: equal-and-silent is equality that proves nothing, and the
    // diagnostics here span both halves — one raised while BUILDING a webhook (and so cached with it)
    // and two raised while DISCOVERING one (and so recomputed every build).
    config()->set('docuccino.documents.default.webhooks.dir', 'tests/Fixtures/Webhooks/Degraded');

    $routes = static function (Router $router): void {
        $router->get('api/forms', [FormController::class, 'index']);
    };

    $warm = assertWarmEqualsCold(
        $routes,
        static function (Router $router) use ($routes): void {
            $routes($router);
            $router->get('api/forms/{form}', [FormController::class, 'show']);
        },
    );

    $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, $warm->diagnostics);

    expect($warm->document->toArray())->toHaveKey('webhooks')
        ->and($codes)->toContain('webhook.name-invalid', 'webhook.method-unknown', 'webhook.payload-unresolved');
});

it('keys a webhook fragment on the files it was built from', function (): void {
    $dir = fragmentCacheDir('warm');
    config()->set('docuccino.documents.default.webhooks.dir', 'workbench/app/Webhooks');

    // The payload class's own files reach the manifest through `SchemaContext::dependsOn()`, which the
    // class mapper feeds from the engine's `ClassMetadata` — so the stub has to answer with them the
    // way the real engine does.
    $widgetData = dirname(__DIR__, 2).'/workbench/app/Data/WidgetData.php';
    app()->instance(TypeEngine::class, WorkbenchEngine::make(classOverrides: [
        'Workbench\\App\\Data\\WidgetData' => new ClassMetadata('Workbench\\App\\Data\\WidgetData', [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('name', ScalarT::string()),
        ], dependencyFiles: [$widgetData]),
    ]));
    generateDocument();

    $dependencies = [];
    foreach (glob($dir.'/*.json') ?: [] as $file) {
        /** @var array<string, mixed> $entry */
        $entry = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (($entry['fragment']['webhook'] ?? false) !== true) {
            continue;
        }

        foreach ($entry['dependencies'] as $dependency) {
            $dependencies[$entry['fragment']['path']][] = basename((string) $dependency['file']);
        }
    }

    // The annotated class is where the attribute, the docblock and — by default — the payload shape
    // are written, so editing it has to invalidate the webhook built from it. A `payload:` naming
    // another class puts that class's files there too, through the converter's own `dependsOn`.
    expect($dependencies)->toHaveKeys(['form.submitted', 'widget.archived'])
        ->and($dependencies['form.submitted'])->toContain('FormSubmitted.php')
        ->and($dependencies['widget.archived'])->toContain('WidgetArchived.php', 'WidgetData.php');
});

it('keeps a webhook to itself when an unrelated one is added beside it', function (): void {
    $anchor = static function (string $dir): array {
        app()->instance(TypeEngine::class, WorkbenchEngine::make(classOverrides: [
            Anchor::class => new ClassMetadata(Anchor::class, [new PropertyMetadata('id', ScalarT::int())]),
            Neighbour::class => new ClassMetadata(Neighbour::class, [new PropertyMetadata('reference', ScalarT::string())]),
        ]));
        config()->set('docuccino.documents.default.webhooks.dir', $dir);

        $document = emittedArray(generateDocument());

        return [$document, $document['webhooks']['locality.anchor']];
    };

    [$alone, $beforeNode] = $anchor('tests/Fixtures/Webhooks/Locality/Anchor');
    [$beside, $afterNode] = $anchor('tests/Fixtures/Webhooks/Locality');

    // The neighbour really did reach the build — a row where it did not would compare equal with the
    // whole discovery layer deleted.
    expect($beside['webhooks'])->toHaveKey('locality.neighbour')
        ->and($alone['webhooks'])->not->toHaveKey('locality.neighbour');

    // …and the anchor's node and every component it reaches are byte-identical across the two.
    expect(json_encode([$afterNode, referencedComponents($beside, $afterNode)], JSON_THROW_ON_ERROR))
        ->toBe(json_encode([$beforeNode, referencedComponents($alone, $beforeNode)], JSON_THROW_ON_ERROR));
});

/**
 * A webhook travels as a fragment, so everything a fragment carries has to be drained where a webhook is
 * consumed exactly as it is where a route is. Diagnostics always were; NOTES were not, and the loop that
 * built webhooks quietly dropped them.
 *
 * Nothing writes a note while a webhook is BUILT today — that path has no `RouteContext` and so no note
 * bag — which is precisely why the drop could not be noticed: a member that is always empty is a member
 * nothing misses until the first producer arrives. What a cached fragment carries is the shape that does
 * exist, and it is the one that would have failed silently: the entry is restored whole, notes included,
 * and a loop that never reads them throws them away on every warm build.
 */
it('drains a warm webhook fragment\'s notes into its collector', function (): void {
    $dir = fragmentCacheDir('warm');
    config()->set('docuccino.documents.default.webhooks.dir', 'workbench/app/Webhooks');
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    // Registered ahead of BOTH builds. The resolved extension set is digested into the fragment-cache
    // key, so a collector added between them would key the second build differently and make it a cold
    // one — which would pass this row for the wrong reason.
    $collector = new CollectedRouteNotes('test.webhook-delivery');
    Docuccino::extend($collector);

    generateDocument();

    $seeded = 0;
    foreach (glob($dir.'/*.json') ?: [] as $file) {
        /** @var array<string, mixed> $entry */
        $entry = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (($entry['fragment']['webhook'] ?? false) !== true || ($entry['fragment']['path'] ?? null) !== 'form.submitted') {
            continue;
        }

        $entry['fragment']['notes'] = ['test.webhook-delivery' => ['payload' => ['FormSubmitted']]];
        file_put_contents($file, json_encode($entry, JSON_THROW_ON_ERROR));
        $seeded++;
    }

    // A seed that matched no entry would leave the assertion below agreeing with a build that never
    // restored anything.
    expect($seeded)->toBe(1);

    generateDocument();

    expect($collector->all())->toBe(['payload' => ['FormSubmitted']]);
});
