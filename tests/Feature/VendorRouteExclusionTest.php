<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Routing\AttributeCollector;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Docuccino\Laravel\Routing\ResolvedRouteIndex;
use Docuccino\Laravel\Routing\RouteReflector;
use Docuccino\Laravel\Routing\VendorRoutePolicy;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * End-to-end proof of the default vendor exclusion through the real resolver: treating the workbench
 * controllers' directory as the "vendor" boundary, a controller route is dropped by default, the
 * include_vendor opt-in restores it, and a closure route is never affected. (The pure per-input
 * decision — incl. application controllers — is dataset-covered in VendorRoutePolicyTest.)
 */
function resolveUris(bool $includeVendor): array
{
    $vendorDir = dirname((string) (new ReflectionClass(FormController::class))->getFileName());

    $resolver = new LaravelRouteResolver(
        app(Router::class),
        new RouteReflector,
        new AttributeCollector,
        new ResolvedRouteIndex,
        new VendorRoutePolicy($vendorDir),
    );

    $document = new DocumentConfig(
        key: 'default',
        info: [],
        routeInclude: ['api/*'],
        includeVendor: $includeVendor,
    );

    return array_map(
        static fn ($descriptor): string => $descriptor->uri,
        iterator_to_array($resolver->resolve($document), false),
    );
}

it('excludes workbench-controller routes by default but keeps the closure route', function (): void {
    $uris = resolveUris(includeVendor: false);

    expect($uris)->toContain('/api/ping')      // closure — unaffected
        ->and($uris)->not->toContain('/api/forms'); // FormController — under the vendor boundary
});

it('opts vendor-controller routes back in with include_vendor', function (): void {
    $uris = resolveUris(includeVendor: true);

    expect($uris)->toContain('/api/forms')
        ->and($uris)->toContain('/api/ping');
});
