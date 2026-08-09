<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\ApiResources\ApiResourcesIntegration;
use Docuccino\Laravel\Integrations\ApiResources\CreatedResourceResponsesExtension;
use Docuccino\Laravel\Integrations\ApiResources\JsonApiParametersExtension;
use Docuccino\Laravel\Integrations\ApiResources\JsonApiResourceSchema;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Integrations\ApiResources\PaginatedResourceResponsesExtension;
use Docuccino\Laravel\Integrations\ApiResources\ResourceMediaType;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateIntegration;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateParametersExtension;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateResponsesExtension;
use Docuccino\Laravel\Integrations\LaravelActions\ActionAuthorizeResponsesExtension;
use Docuccino\Laravel\Integrations\LaravelActions\ActionValidationExtension;
use Docuccino\Laravel\Integrations\LaravelActions\LaravelActionsIntegration;
use Docuccino\Laravel\Integrations\Passport\PassportIntegration;
use Docuccino\Laravel\Integrations\Passport\PassportSecurityExtension;
use Docuccino\Laravel\Integrations\Permission\PermissionExtension;
use Docuccino\Laravel\Integrations\Permission\PermissionIntegration;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderIntegration;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Integrations\Sanctum\SanctumIntegration;
use Docuccino\Laravel\Integrations\Sanctum\SanctumSecurityExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataRequestExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\SpatieDataIntegration;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldJsonApiIntegration;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldJsonApiParametersExtension;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldJsonApiResourceSchema;

/**
 * The false branch of every conditional (`class_exists`-guarded) integration: in the test environment
 * the target packages ARE installed, so the "package absent" path never runs unless the class-presence
 * probe is injected. Each gate takes an injectable probe; forcing it false must drop the integration's
 * extensions from the resolved set (and forcing it true must keep them), so an app without the package
 * documents cleanly rather than referencing a class that does not exist.
 *
 * @param  class-string  $integration
 * @param  list<class-string>  $conditionalExtensions
 */
it('drops a conditional integration from the resolved set when its package is absent', function (string $integration, array $conditionalExtensions): void {
    $absent = static fn (string $class): bool => false;
    $present = static fn (string $class): bool => true;

    expect($integration::installed($absent))->toBeFalse()
        ->and($integration::installed($present))->toBeTrue()
        // No probe → the real class_exists; the package is installed in this suite.
        ->and($integration::installed())->toBeTrue();

    // The provider composes `installed(...) ? extensions() : []`; gated off yields none of them.
    $resolved = $integration::installed($absent) ? $integration::extensions() : [];
    expect($resolved)->toBe([]);

    foreach ($conditionalExtensions as $extension) {
        expect($integration::extensions())->toContain($extension);
    }
})->with([
    'spatie/laravel-data' => [SpatieDataIntegration::class, [DataSchema::class, DataRequestExtension::class]],
    'spatie/laravel-query-builder' => [QueryBuilderIntegration::class, [QueryBuilderParametersExtension::class]],
    'laravel/sanctum' => [SanctumIntegration::class, [SanctumSecurityExtension::class]],
    'laravel/passport' => [PassportIntegration::class, [PassportSecurityExtension::class]],
    'spatie/laravel-permission' => [PermissionIntegration::class, [PermissionExtension::class]],
    'spatie/laravel-json-api-paginate' => [JsonApiPaginateIntegration::class, [JsonApiPaginateParametersExtension::class, JsonApiPaginateResponsesExtension::class]],
    'timacdonald/json-api' => [TimacdonaldJsonApiIntegration::class, [TimacdonaldJsonApiResourceSchema::class, TimacdonaldJsonApiParametersExtension::class]],
    'lorisleiva/laravel-actions' => [LaravelActionsIntegration::class, [ActionValidationExtension::class, ActionAuthorizeResponsesExtension::class]],
]);

it('omits the JSON:API pieces on a Laravel without the first-party JsonApiResource class', function (): void {
    $absent = static fn (string $class): bool => false;
    $present = static fn (string $class): bool => true;

    // Absent → the always-on JsonResource mapper + paginated-collection response extension; JSON:API
    // schema + params dropped.
    expect(ApiResourcesIntegration::extensions($absent))
        ->toBe([JsonResourceSchema::class, PaginatedResourceResponsesExtension::class, CreatedResourceResponsesExtension::class, ResourceMediaType::class]);

    // Present → the JSON:API mapper and parameters extension join the set.
    expect(ApiResourcesIntegration::extensions($present))
        ->toBe([JsonResourceSchema::class, PaginatedResourceResponsesExtension::class, CreatedResourceResponsesExtension::class, ResourceMediaType::class, JsonApiResourceSchema::class, JsonApiParametersExtension::class]);
});
