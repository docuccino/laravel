<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionRef;
use Docuccino\Laravel\Integrations\LaravelActions\LaravelAction;
use Docuccino\Laravel\Routing\LaravelActionRouteMethod;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\ArchiveArticleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\ExplicitMethodAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\HandlelessAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\HtmlResponseAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\InheritedArticleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\JsonResponseAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\PublishArticleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\SimpleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\WithAttributesAction;
use Workbench\App\Http\Controllers\FormController;

/**
 * The action detection + route-method resolution — the one place laravel-actions changes how a route
 * maps to a method. Dataset-driven over the registration styles the package supports (invokable
 * action with asController, with only handle, with neither; an explicitly-registered method; a plain
 * non-action controller) so every branch of the asController > handle > __invoke precedence + the
 * non-action degradation is covered with real reflection.
 */
it('recognises an action by its AsController/AsAction trait', function (string $class, bool $expected): void {
    expect(LaravelAction::isAction($class))->toBe($expected);
})->with([
    'AsAction umbrella trait' => [PublishArticleAction::class, true],
    'AsController trait directly' => [ArchiveArticleAction::class, true],
    'trait inherited via a parent class' => [InheritedArticleAction::class, true],
    'plain controller (no trait)' => [FormController::class, false],
]);

it('resolves the dispatched controller method across registration styles', function (string $class, string $registered, string $resolved): void {
    // The route-IDENTITY remap is a non-toggleable routing probe (relocated out of the integration).
    expect(LaravelActionRouteMethod::resolve($class, $registered))->toBe($resolved);
})->with([
    'invokable action with asController → asController' => [ArchiveArticleAction::class, '__invoke', 'asController'],
    'invokable action with only handle → handle' => [PublishArticleAction::class, '__invoke', 'handle'],
    'invokable minimal action → handle' => [SimpleAction::class, '__invoke', 'handle'],
    'explicit method registration is honoured verbatim' => [ArchiveArticleAction::class, 'handle', 'handle'],
    'inherited-trait action resolves through the parent to handle' => [InheritedArticleAction::class, '__invoke', 'handle'],
    // Neither asController nor handle → the invokable registration falls through to __invoke verbatim.
    'action with neither asController nor handle falls back to the registered method' => [HandlelessAction::class, '__invoke', '__invoke'],
    'non-action invokable controller is unchanged' => [FormController::class, '__invoke', '__invoke'],
]);

it('reports whether the package would validate the dispatched method (rules()/authorize() gate)', function (string $class, string $method, bool $expected): void {
    expect(LaravelAction::dispatchesValidation($class, $method))->toBe($expected);
})->with([
    // Non-explicit dispatch methods on a plain action validate.
    'handle on a plain action' => [PublishArticleAction::class, 'handle', true],
    'asController on a plain action' => [ArchiveArticleAction::class, 'asController', true],
    '__invoke on a plain action' => [PublishArticleAction::class, '__invoke', true],
    // An explicitly-registered method never validates.
    'explicit method' => [ExplicitMethodAction::class, 'store', false],
    // A WithAttributes action opts out of validation entirely.
    'WithAttributes action via handle' => [WithAttributesAction::class, 'handle', false],
    // A non-action class is never gated on.
    'non-action controller' => [FormController::class, '__invoke', false],
]);

it('redirects the success-body analysis to jsonResponse() only when the action defines it', function (string $class, ?string $expectedMethod): void {
    $ref = LaravelAction::responseAnalysisRef(new ActionRef('', $class, 'handle'));

    if ($expectedMethod === null) {
        expect($ref)->toBeNull();

        return;
    }

    expect($ref)->not->toBeNull()
        ->and($ref->class)->toBe($class)
        ->and($ref->method)->toBe($expectedMethod)
        ->and($ref->file)->not->toBe('')
        ->and($ref->line)->toBeGreaterThan(0);
})->with([
    'action defining jsonResponse → redirected' => [JsonResponseAction::class, 'jsonResponse'],
    'action without jsonResponse → no redirect' => [PublishArticleAction::class, null],
    'htmlResponse-only action → no jsonResponse redirect' => [HtmlResponseAction::class, null],
    'non-action controller → no redirect' => [FormController::class, null],
]);

it('detects an htmlResponse() action', function (?string $class, bool $expected): void {
    expect(LaravelAction::definesHtmlResponse($class))->toBe($expected);
})->with([
    'action defining htmlResponse' => [HtmlResponseAction::class, true],
    'action without htmlResponse' => [PublishArticleAction::class, false],
    'jsonResponse-only action' => [JsonResponseAction::class, false],
    'non-action controller' => [FormController::class, false],
    'null class (closure route)' => [null, false],
]);
