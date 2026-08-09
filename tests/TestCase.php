<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests;

use Docuccino\Laravel\DocuccinoServiceProvider;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Gadget;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Http\Controllers\BrokenController;
use Workbench\App\Http\Controllers\FormController;
use Workbench\App\Http\Controllers\IntegrationsController;
use Workbench\App\Http\Controllers\SecretController;
use Workbench\App\Http\Controllers\ValidationController;
use Workbench\App\Http\Controllers\WidgetController;
use Workbench\App\Http\Controllers\WidgetQueryController;

/**
 * The base testbench case for the Laravel adapter: registers the package provider and the
 * workbench routes exercised by the feature tests (plain, route-model-bound, attribute-decorated,
 * closure, excluded, and a deliberately-broken route).
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DocuccinoServiceProvider::class];
    }

    /**
     * The default `viewer.middleware` includes the `web` group, whose session/cookie encryption
     * needs an application key — set a fixed one so the viewer feature tests run as a real app would.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:AckfSECXIvnK5r28GVIWUAxmbBSjTsmF0FYqwoDL18E=');

        // The workbench documents authorization requirements (the api/moderated-forms route), so it
        // opts the default document into the now-default-off spatie/laravel-permission integration
        // explicitly. Setting it here (the fixture's document config) rather than in the shipped
        // config preserves the opt-in default for real apps while the permission goldens stay
        // byte-stable now that the integration is opt-in.
        $app['config']->set('docuccino.documents.default.integrations.permission.enabled', true);

        // The morph map the /api/attachments discriminator resolves its aliases from (design §Phase 4).
        Relation::morphMap(['widget' => Widget::class, 'gadget' => Gadget::class], false);
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('api/forms', [FormController::class, 'index']);
        $router->get('api/forms/{form}', [FormController::class, 'show']);
        $router->post('api/widgets', [WidgetController::class, 'store']);
        $router->post('api/tickets', [ValidationController::class, 'store']);
        $router->get('api/secret', [SecretController::class, 'index']);
        $router->get('api/broken', [BrokenController::class, 'ghost']);
        $router->get('api/ping', static fn () => response()->json(['pong' => true]));

        // Phase-4b wave-1 integration routes: Query Builder (scripted trace), rate limiting,
        // spatie/laravel-permission, and a withTrashed route-model binding.
        $router->get('api/widget-query', [WidgetQueryController::class, 'index']);
        $router->get('api/rate-limited', [FormController::class, 'index'])->middleware('throttle:60,1');
        $router->get('api/moderated-forms', [FormController::class, 'index'])->middleware('permission:moderate forms,web');
        $router->get('api/archived-forms/{form}', [FormController::class, 'show'])->withTrashed();
        // An authenticated + authorized route: exercises the implicit 401 (auth middleware) and
        // implicit 403 (can: middleware) responses through the full pipeline into the golden. Uses the
        // session `web` guard so no Sanctum/Passport security scheme is emitted into the default doc —
        // the 401 response is independent of whether a scheme is configured (matching Scramble).
        $router->get('api/guarded-forms', [FormController::class, 'index'])->middleware(['auth:web', 'can:view']);

        // Phase-4 integration routes (Spatie Data, API Resources, JSON:API, Eloquent, status codes).
        $router->post('api/articles', [IntegrationsController::class, 'storeArticle']);
        $router->get('api/article-resources', [IntegrationsController::class, 'listArticleResources']);
        $router->get('api/paginated-articles', [IntegrationsController::class, 'listPaginatedArticles']);
        $router->get('api/json-paginated-articles', [IntegrationsController::class, 'listJsonPaginatedArticles']);
        $router->get('api/article-resources/{id}', [IntegrationsController::class, 'showArticleResource']);
        $router->post('api/created-articles', [IntegrationsController::class, 'storeCreatedArticle']);
        $router->get('api/jsonapi-articles/{id}', [IntegrationsController::class, 'showJsonApiArticle']);
        $router->get('api/model-widgets/{id}', [IntegrationsController::class, 'showWidget']);
        $router->delete('api/model-widgets/{id}', [IntegrationsController::class, 'destroyWidget']);
        $router->post('api/reports', [IntegrationsController::class, 'storeReport']);

        // Phase-4b wave-2 routes: a polymorphic morph (discriminated oneOf) and a renderable
        // exception the inferred-handler tier documents.
        $router->get('api/attachments/{id}', [IntegrationsController::class, 'showAttachment']);
        $router->post('api/checkout', [IntegrationsController::class, 'checkout']);
    }
}
