<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Fixtures\ShortNameResponse\PanelController;
use Illuminate\Routing\Router;

/**
 * End-to-end proof that a `#[Response(type: '<ShortName>')]` short class name resolves through the
 * controller file's ImportContext into the real body schema — the feature a real Scramble migration
 * leans on, previously covered only at the TypeStringParser unit level. A resolution failure would
 * degrade the 200 body to a bare object with no properties, so asserting PanelData's resolved
 * properties is the signal.
 */
it('resolves a short-name #[Response] type through the controller ImportContext end-to-end', function (): void {
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/panel', [PanelController::class, 'show']);

    $document = generateDocument()->document->toArray();

    $schema = $document['paths']['/api/panel']['get']['responses']['200']['content']['application/json']['schema'];

    // The short name `WidgetData` resolved (through the file's `use` import) to the real
    // Workbench\App\Data\WidgetData class: the body hoists to a #/components/schemas/WidgetData $ref,
    // and that component carries WidgetData's typed properties. A resolution failure would degrade the
    // body to a bare object with neither a $ref nor the hoisted component.
    expect($schema['$ref'] ?? null)->toBe('#/components/schemas/WidgetData');

    $component = $document['components']['schemas']['WidgetData'];
    expect($component['type'])->toBe('object')
        ->and($component['properties'])->toHaveKeys(['id', 'name', 'status']);
});
