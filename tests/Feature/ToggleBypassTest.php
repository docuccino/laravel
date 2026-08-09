<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ResponseAnalysisRedirect;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\PayloadMediaTypeResolver;
use Docuccino\Core\Extensions\Contracts\ResponseAnalysisTarget;
use Docuccino\Core\Extensions\Contracts\ResponseStatusResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Integrations\ApiResources\ResourceMediaType;
use Docuccino\Laravel\Integrations\Eloquent\EloquentRouteBindingSchema;
use Docuccino\Laravel\Integrations\LaravelActions\LaravelActionResponseAnalysis;
use Docuccino\Laravel\Integrations\SpatieData\DataResponseStatus;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldMediaType;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;

/**
 * The toggle-bypass fix (arch review PIN 1): an integration contributes to output ONLY when installed
 * AND enabled-for-this-document. Every output-shaping decision an integration used to make through a
 * static call an extension imported directly now flows through a GATED context chain, so disabling the
 * integration removes its contribution entirely — proven here two ways: (1) a disabled integration puts
 * nothing in the resolved resolver partition, and (2) the built-in extensions read only those chains,
 * so an empty chain is inert (the neutral default) while a populated one drives the decision.
 */
function resolvedWith(string $integration, bool $enabled): ResolvedExtensions
{
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $raw['integrations'][$integration]['enabled'] = $enabled;

    $document = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    return app(ExtensionRegistry::class)->resolve(app(), DefaultExtensions::all($document), []);
}

/** @param  list<object>  $chain */
function chainHas(array $chain, string $class): bool
{
    foreach ($chain as $entry) {
        if ($entry instanceof $class) {
            return true;
        }
    }

    return false;
}

it('drops the laravel-actions response-analysis target when the integration is disabled', function (): void {
    expect(chainHas(resolvedWith('laravel_actions', true)->responseAnalysisTargets, LaravelActionResponseAnalysis::class))->toBeTrue()
        ->and(chainHas(resolvedWith('laravel_actions', false)->responseAnalysisTargets, LaravelActionResponseAnalysis::class))->toBeFalse();
});

it('drops the spatie-data response-status resolver when the integration is disabled', function (): void {
    expect(chainHas(resolvedWith('spatie_data', true)->responseStatusResolvers, DataResponseStatus::class))->toBeTrue()
        ->and(chainHas(resolvedWith('spatie_data', false)->responseStatusResolvers, DataResponseStatus::class))->toBeFalse();
});

it('drops the eloquent route-binding schema resolver when the integration is disabled', function (): void {
    expect(chainHas(resolvedWith('eloquent', true)->routeBindingSchemaResolvers, EloquentRouteBindingSchema::class))->toBeTrue()
        ->and(chainHas(resolvedWith('eloquent', false)->routeBindingSchemaResolvers, EloquentRouteBindingSchema::class))->toBeFalse();
});

it('drops each resource media-type matcher when its own integration is disabled', function (): void {
    // Each family's matcher is gated independently — disabling one leaves the other's in place.
    expect(chainHas(resolvedWith('api_resources', true)->payloadMediaTypeResolvers, ResourceMediaType::class))->toBeTrue()
        ->and(chainHas(resolvedWith('api_resources', false)->payloadMediaTypeResolvers, ResourceMediaType::class))->toBeFalse()
        ->and(chainHas(resolvedWith('timacdonald_json_api', true)->payloadMediaTypeResolvers, TimacdonaldMediaType::class))->toBeTrue()
        ->and(chainHas(resolvedWith('timacdonald_json_api', false)->payloadMediaTypeResolvers, TimacdonaldMediaType::class))->toBeFalse();
});

/**
 * The context accessors are the seam the built-in extensions read: an empty chain (a disabled
 * integration) is inert (the neutral default), a populated one drives the decision. Together with the
 * gating above and the arch rule that extensions never import an integration, this proves a disabled
 * integration cannot shape the success response, its status, its media type, or a bound path param.
 */
function contextWithChains(
    array $responseAnalysisTargets = [],
    array $responseStatusResolvers = [],
    array $payloadMediaTypeResolvers = [],
    array $routeBindingSchemaResolvers = [],
): RouteContext {
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/x'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        responseAnalysisTargets: $responseAnalysisTargets,
        responseStatusResolvers: $responseStatusResolvers,
        payloadMediaTypeResolvers: $payloadMediaTypeResolvers,
        routeBindingSchemaResolvers: $routeBindingSchemaResolvers,
    );
}

it('returns the neutral default from each context chain when empty, and the contribution when populated', function (): void {
    $empty = contextWithChains();
    $payload = new ClassT('App\\SomeResource');

    // Empty chains → neutral defaults (a disabled integration is inert).
    expect($empty->responseAnalysisRedirect())->toBeNull()
        ->and($empty->resolveResponseStatuses('App\\Data'))->toBe([])
        ->and($empty->payloadMediaType($payload))->toBe('application/json')
        ->and($empty->routeBindingKeySchema('App\\Models\\Post'))->toBeNull();

    // Populated chains → the contributed decision.
    $populated = contextWithChains(
        responseAnalysisTargets: [new class implements ResponseAnalysisTarget
        {
            public function resolve(RouteContext $context): ?ResponseAnalysisRedirect
            {
                return new ResponseAnalysisRedirect(new ActionRef('f', 'C', 'jsonResponse'), 'integration:laravel-actions');
            }
        }],
        responseStatusResolvers: [new class implements ResponseStatusResolver
        {
            public function resolveStatuses(RouteContext $context, string $fqcn): array
            {
                return [201];
            }
        }],
        payloadMediaTypeResolvers: [new class implements PayloadMediaTypeResolver
        {
            public function mediaTypeFor(DType $payload): ?string
            {
                return 'application/vnd.api+json';
            }
        }],
        routeBindingSchemaResolvers: [new class implements RouteBindingSchemaResolver
        {
            public function keySchemaFor(string $modelFqcn): ?array
            {
                return ['type' => 'string', 'format' => 'uuid'];
            }
        }],
    );

    expect($populated->responseAnalysisRedirect()?->producer)->toBe('integration:laravel-actions')
        ->and($populated->resolveResponseStatuses('App\\Data'))->toBe([201])
        ->and($populated->payloadMediaType($payload))->toBe('application/vnd.api+json')
        ->and($populated->routeBindingKeySchema('App\\Models\\Post'))->toBe(['type' => 'string', 'format' => 'uuid']);
});
