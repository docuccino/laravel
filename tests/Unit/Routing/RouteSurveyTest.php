<?php

declare(strict_types=1);

use Docuccino\Laravel\Routing\RoutePrefix;
use Docuccino\Laravel\Routing\RouteSurvey;
use Docuccino\Laravel\Routing\VendorRoutePolicy;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * The survey answers "where do this application's routes actually live" for `docuccino:install`. It
 * is only worth printing if it narrows the same way a build does — vendor controllers and HEAD-only
 * routes are not candidates for any document — and if its order comes from the counts rather than
 * from registration order, which is the order a reader would otherwise mistake for significance.
 */
function surveyRouter(): Router
{
    return new Router(new Dispatcher, app());
}

it('groups routes by first segment, busiest first and ties broken by name', function (): void {
    $router = surveyRouter();
    $router->get('v1/users', static fn (): string => '');
    $router->post('v1/users', static fn (): string => '');
    $router->get('v1/orders', static fn (): string => '');
    $router->get('admin/panel', static fn (): string => '');
    $router->get('zebra/one', static fn (): string => '');

    expect(array_map(
        static fn (RoutePrefix $prefix): array => [$prefix->prefix, $prefix->count],
        (new RouteSurvey($router))->prefixes(),
    ))->toBe([['v1', 3], ['admin', 1], ['zebra', 1]]);
});

it('counts each verb on one URI as its own route', function (): void {
    $router = surveyRouter();
    $router->get('api/widgets', static fn (): string => '');
    $router->post('api/widgets', static fn (): string => '');

    expect((new RouteSurvey($router))->paths())->toBe(['api/widgets', 'api/widgets']);
});

it('names the include pattern each prefix would need', function (string $uri, string $prefix, string $pattern): void {
    $router = surveyRouter();
    $router->get($uri, static fn (): string => '');

    $prefixes = (new RouteSurvey($router))->prefixes();

    expect($prefixes[0]->prefix)->toBe($prefix)
        ->and($prefixes[0]->pattern())->toBe($pattern);
})->with([
    'nested' => ['v1/users/{user}', 'v1', 'v1/*'],
    'single segment' => ['health', 'health', 'health/*'],
    'a parameter first' => ['{tenant}/users', '{tenant}', '{tenant}/*'],
    // The root route has no segment to name, so it is reported as itself rather than as `/*`.
    'root' => ['/', '/', '/'],
]);

it('leaves out a route no document could include', function (): void {
    $router = surveyRouter();
    $router->get('api/kept', static fn (): string => '');
    $router->addRoute(['HEAD'], 'api/head-only', static fn (): string => '');

    expect((new RouteSurvey($router))->paths())->toBe(['api/kept']);
});

/**
 * Vendor exclusion is a question about the controller's FILE, so the boundary is pointed at the
 * workbench controller's own directory: the same route reads as vendor or not depending only on
 * where the policy says vendor is.
 */
it('drops a vendor controller route, and only when a boundary says where vendor is', function (): void {
    $router = surveyRouter();
    $router->get('api/closure', static fn (): string => '');
    $router->get('api/controller', [FormController::class, 'index']);

    $vendor = dirname((string) (new ReflectionClass(FormController::class))->getFileName());

    expect((new RouteSurvey($router))->paths())->toBe(['api/closure', 'api/controller'])
        ->and((new RouteSurvey($router, vendorPolicy: new VendorRoutePolicy($vendor)))->paths())
        ->toBe(['api/closure']);
});

it('has nothing to report on an application with no routes', function (): void {
    $survey = new RouteSurvey(surveyRouter());

    expect($survey->paths())->toBe([])
        ->and($survey->prefixes())->toBe([]);
});
