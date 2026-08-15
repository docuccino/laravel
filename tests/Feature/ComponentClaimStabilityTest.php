<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\ClaimController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * Every path that stakes a claim on a component name, held to the same rule: the name a schema is
 * published under is a function of what it IS, so adding, removing or reordering a route can add a
 * component and can never change one it did not touch.
 *
 * Three claims that break it silently, in a green build, if the name is the slot: a class hoisted twice
 * — its request shape beside its response shape — lands on `Foo`/`Foo_2` by route order; a `#[SchemaId]`
 * pin carrying no namespace makes its whole group fall back to that same positional suffix; and a class
 * the analyser cannot expand holds a name it never publishes, renaming a working component beside it.
 */
const CLAIM_PORTAL = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\PortalData';
const CLAIM_API_USER = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Api\\UserData';
const CLAIM_ADMIN_USER = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Admin\\UserData';
const CLAIM_BROKEN_GIZMO = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Broken\\Gizmo';
const CLAIM_WORKING_GIZMO = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Working\\Gizmo';

/**
 * Build with a chosen set of the claim routes. Routes process in sorted `METHOD uri` order, so which
 * of them is present decides who registers first — which is exactly what must not reach the output.
 *
 * @param  list<string>  $only  action names; every claim route by default
 */
function claimDocument(array $only = ['show', 'store', 'apiUser', 'adminUser', 'brokenGizmo', 'workingGizmo']): GenerationResult
{
    $routes = [
        'show' => ['get', 'api/zz-portal'],
        'store' => ['post', 'api/zz-portal'],
        'apiUser' => ['get', 'api/zz-user-api'],
        'adminUser' => ['get', 'api/zz-user-admin'],
        'brokenGizmo' => ['get', 'api/zz-gizmo-broken'],
        'workingGizmo' => ['get', 'api/zz-gizmo-working'],
    ];

    foreach ($only as $action) {
        [$verb, $uri] = $routes[$action];
        app('router')->{$verb}($uri, [ClaimController::class, $action]);
    }

    $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(
        returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))],
    );

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        classOverrides: [
            CLAIM_PORTAL => new ClassMetadata(CLAIM_PORTAL, [
                new PropertyMetadata('id', ScalarT::int()),
                new PropertyMetadata('name', ScalarT::string()),
                new PropertyMetadata('token', ScalarT::string()),
            ]),
            CLAIM_API_USER => new ClassMetadata(CLAIM_API_USER, [new PropertyMetadata('handle', ScalarT::string())]),
            CLAIM_ADMIN_USER => new ClassMetadata(CLAIM_ADMIN_USER, [new PropertyMetadata('email', ScalarT::string())]),
            CLAIM_WORKING_GIZMO => new ClassMetadata(CLAIM_WORKING_GIZMO, [new PropertyMetadata('id', ScalarT::int())]),
            // Broken\Gizmo is deliberately absent: an unknown class is what the analyser giving
            // nothing back looks like from here.
        ],
        analysisOverrides: [
            ClaimController::class.'::show' => $returns(CLAIM_PORTAL),
            ClaimController::class.'::apiUser' => $returns(CLAIM_API_USER),
            ClaimController::class.'::adminUser' => $returns(CLAIM_ADMIN_USER),
            ClaimController::class.'::brokenGizmo' => $returns(CLAIM_BROKEN_GIZMO),
            ClaimController::class.'::workingGizmo' => $returns(CLAIM_WORKING_GIZMO),
        ],
    ));

    return generateDocument();
}

/**
 * @return array<string, array<string, mixed>> the document's `components.schemas`
 */
function claimSchemas(GenerationResult $result): array
{
    $schemas = $result->document->toArray()['components']['schemas'] ?? [];

    return is_array($schemas) ? $schemas : [];
}

/** The `$ref` at a JSON pointer through the document, or null. */
function claimRef(GenerationResult $result, string ...$path): ?string
{
    $node = $result->document->toArray();
    foreach ($path as $key) {
        $node = is_array($node) ? ($node[$key] ?? null) : null;
    }

    return is_array($node) ? ($node['$ref'] ?? null) : null;
}

afterEach(function (): void {
    removeFragmentCacheDirs('claims');
});

it('names a class request shape for what it is, so its own shape can keep the plain name', function (): void {
    // `GET api/zz-portal` sorts first, so the RESPONSE registers before the request — the opposite of
    // the order the workbench meets them in, and under a positional suffix that alone decided which
    // shape `Portal` meant.
    $result = claimDocument();
    $schemas = claimSchemas($result);

    expect($schemas)->toHaveKeys(['Portal', 'PortalRequest'])
        ->and($schemas)->not->toHaveKey('Portal_2')
        // What a client receives: the mapped output name, and nothing the class hides.
        ->and(array_keys($schemas['Portal']['properties'] ?? []))->toBe(['id', 'displayName'])
        // What a client sends: the input names, token included.
        ->and(array_keys($schemas['PortalRequest']['properties'] ?? []))->toBe(['id', 'handle', 'token'])
        ->and(claimRef($result, 'paths', '/api/zz-portal', 'post', 'requestBody', 'content', 'application/json', 'schema'))
        ->toBe('#/components/schemas/PortalRequest')
        ->and(claimRef($result, 'paths', '/api/zz-portal', 'get', 'responses', '200', 'content', 'application/json', 'schema'))
        ->toBe('#/components/schemas/Portal');
});

it('leaves the request shape exactly where it was when the read route is added', function (): void {
    // Locality. The write route is documented identically whether or not anything reads the class —
    // adding the read route may only ADD `Portal`.
    $alone = claimDocument(['store']);
    $both = claimDocument(['show', 'store']);

    expect(claimSchemas($alone))->toHaveKey('PortalRequest')
        ->and(claimSchemas($alone))->not->toHaveKey('Portal')
        ->and(claimSchemas($both)['PortalRequest'] ?? null)->toBe(claimSchemas($alone)['PortalRequest'] ?? null)
        ->and($both->document->toArray()['paths']['/api/zz-portal']['post'] ?? null)
        ->toEqual($alone->document->toArray()['paths']['/api/zz-portal']['post'] ?? null);
});

it('keeps a #[SchemaId]-pinned class as stable as an unpinned one', function (): void {
    // The pin exists to keep a schema's identity still across a class rename. Reading it as "no
    // namespace, therefore unidentifiable" made the pair fall back to a positional suffix — so using
    // the feature that exists for stability turned stability off.
    $forwards = claimDocument(['apiUser', 'adminUser']);
    $backwards = claimDocument(['adminUser', 'apiUser']);

    $names = array_keys(array_filter(
        claimSchemas($forwards),
        static fn (string $name): bool => str_starts_with($name, 'UserData'),
        ARRAY_FILTER_USE_KEY,
    ));

    expect((new UirEmitter)->emit($backwards->document))->toBe((new UirEmitter)->emit($forwards->document))
        ->and($names)->toHaveCount(2)
        // The contested plain name is retired, so nothing can be reading it and getting the other shape.
        ->and($names)->not->toContain('UserData')
        ->and($names)->not->toContain('UserData_2')
        ->and(claimSchemas($forwards)[$names[0]]['properties'] ?? [])->not->toBe(claimSchemas($forwards)[$names[1]]['properties'] ?? []);
});

it('warns about the pinned pair, naming each pin and the name it was published under', function (): void {
    // A hash-discriminated name is stable but it says nothing, so the diagnostic has to hand the
    // author the better answer and name the claimants in terms they can find in their own source.
    $result = claimDocument(['apiUser', 'adminUser']);
    $warnings = array_values(array_filter(
        diagnosticsCoded($result->diagnostics, 'components.name-collision'),
        static fn ($d): bool => str_contains($d->message, '"UserData"'),
    ));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->severity->value)->toBe('warning')
        ->and($warnings[0]->message)->toContain('user.public.v1 as "')
        ->toContain('user.admin.v1 as "')
        ->and($warnings[0]->help)->toContain('#[SchemaName]');
});

it('lets a class it cannot expand degrade without touching the working class beside it', function (): void {
    // A reservation taken up front and never released holds `Gizmo` for a class that publishes nothing,
    // pushing the working one onto `Gizmo_2` — renamed by a route that contributed nothing to the
    // document, and with no collision to warn anyone about.
    $without = claimDocument(['workingGizmo']);
    $with = claimDocument(['brokenGizmo', 'workingGizmo']);

    expect(claimSchemas($with))->toHaveKey('Gizmo')
        ->and(claimSchemas($with))->not->toHaveKey('Gizmo_2')
        ->and(claimSchemas($with)['Gizmo'] ?? null)->toBe(claimSchemas($without)['Gizmo'] ?? null)
        ->and(claimRef($with, 'paths', '/api/zz-gizmo-working', 'get', 'responses', '200', 'content', 'application/json', 'schema'))
        ->toBe('#/components/schemas/Gizmo')
        // The degraded route still says something true — an object, inline, claiming no name, and
        // saying so with a confidence a reader can see.
        ->and(stripDocuccino($with->document->toArray()['paths']['/api/zz-gizmo-broken']['get']['responses']['200']['content']['application/json']['schema'] ?? []))
        ->toBe(['type' => 'object']);
});

it('publishes the same claims, and the same diagnostics, on a warm fragment-cache build', function (): void {
    $dir = fragmentCacheDir('claims');

    $cold = claimDocument();
    $warm = claimDocument();

    expect((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and(diagnosticsCoded($warm->diagnostics, 'components.name-collision'))
        ->toEqual(diagnosticsCoded($cold->diagnostics, 'components.name-collision'))
        ->not->toBeEmpty();
});
