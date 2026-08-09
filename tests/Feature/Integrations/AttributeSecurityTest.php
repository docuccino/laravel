<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\AuthAttributesController;

/**
 * Real-path coverage for the attribute security layer (#[Security] / #[OptionallyAuthenticated]):
 * the attributes are reflected off real controller methods and applied through the pipeline over the
 * inferred/config security, at attribute precedence.
 */
function attributeSecurityDocument(callable $mutate): array
{
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $raw = $mutate($raw);

    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();
}

it('emits an OR-list of requirements from repeated #[Security]', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/reports', [AuthAttributesController::class, 'reports']);

    $document = attributeSecurityDocument(static fn (array $raw): array => $raw);

    expect($document['paths']['/api/reports']['get']['security'])->toBe([
        ['oauth2' => ['reports.read']],
        ['apiKey' => []],
    ]);
});

it('overrides an inferred requirement with an explicit #[Security]', function (): void {
    /** @var Router $router */
    $router = app('router');
    // The route is Sanctum-protected by middleware, but the action declares an explicit requirement:
    // the attribute layer (40) wins over the integration layer (20).
    $router->get('api/reports', [AuthAttributesController::class, 'reports'])->middleware('auth:sanctum');

    $document = attributeSecurityDocument(static fn (array $raw): array => $raw);

    expect($document['paths']['/api/reports']['get']['security'])->toBe([
        ['oauth2' => ['reports.read']],
        ['apiKey' => []],
    ]);
});

it('prepends the anonymous requirement to the inferred one for #[OptionallyAuthenticated]', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/feed', [AuthAttributesController::class, 'feed'])->middleware('auth:sanctum');

    $document = attributeSecurityDocument(static fn (array $raw): array => $raw);

    // Anonymous OR the Sanctum token inferred from the middleware.
    expect($document['paths']['/api/feed']['get']['security'])->toBe([
        [],
        ['sanctumToken' => []],
    ]);
});

it('emits a bare anonymous requirement for #[OptionallyAuthenticated] on an otherwise-public route', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/feed', [AuthAttributesController::class, 'feed']);

    $document = attributeSecurityDocument(static fn (array $raw): array => $raw);

    expect($document['paths']['/api/feed']['get']['security'])->toBe([[]]);
});

it('does not touch security on routes without any security attribute', function (): void {
    $document = attributeSecurityDocument(static fn (array $raw): array => $raw);

    expect($document['paths']['/api/forms']['get'])->not->toHaveKey('security');
});
