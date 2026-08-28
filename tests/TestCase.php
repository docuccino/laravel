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
use Workbench\App\Http\Controllers\LedgerQueryController;
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

        // api/moderated-forms documents authorization requirements, so the default document opts into the
        // spatie/laravel-permission integration. Doing it here rather than in the shipped config keeps the
        // opt-in default for real apps while the permission goldens stay byte-stable.
        $app['config']->set('docuccino.documents.default.integrations.permission.enabled', true);

        // The morph map the /api/attachments discriminator resolves its aliases from.
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

        // Query Builder (scripted trace), rate limiting, spatie/laravel-permission, and a withTrashed
        // route-model binding.
        $router->get('api/widget-query', [WidgetQueryController::class, 'index']);
        // Include + sparse-fieldset allow-lists, so a golden locks those two enums and their prose.
        $router->get('api/ledger-query', [LedgerQueryController::class, 'index']);
        $router->get('api/rate-limited', [FormController::class, 'index'])->middleware('throttle:60,1');
        $router->get('api/moderated-forms', [FormController::class, 'index'])->middleware('permission:moderate forms,web');
        $router->get('api/archived-forms/{form}', [FormController::class, 'show'])->withTrashed();
        // An authenticated + authorized route: the implicit 401 (auth middleware) and 403 (can:
        // middleware) responses through the full pipeline into the golden. It uses the session `web` guard
        // so no Sanctum/Passport security scheme lands in the default doc — the 401 doesn't depend on one
        // being configured.
        $router->get('api/guarded-forms', [FormController::class, 'index'])->middleware(['auth:web', 'can:view']);

        // Spatie Data, API Resources, JSON:API, Eloquent and status-code routes.
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

        // A polymorphic morph (discriminated oneOf) and a renderable exception the inferred-handler tier
        // documents.
        $router->get('api/attachments/{id}', [IntegrationsController::class, 'showAttachment']);
        $router->post('api/checkout', [IntegrationsController::class, 'checkout']);

        // Two model responses whose published keys are decided by the visibility lists rather than by
        // the column set: a deny-list reaching an append and an eager load, and an allow-list a
        // deny-list narrows further.
        $router->get('api/strongboxes/{id}', [IntegrationsController::class, 'showStrongbox']);
        $router->get('api/showcases/{id}', [IntegrationsController::class, 'showShowcase']);
    }
}
