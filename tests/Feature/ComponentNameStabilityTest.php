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
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\SsoController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * The permanent collision a real app carries: an INPUT `SSOConnectionData` and an OUTPUT one, in
 * different namespaces, different shapes, both legitimately published. Nobody is going to "fix" that,
 * so the names have to be good — and above all STABLE.
 *
 * The defect these pin: a positional `_2` hands the plain name to whichever class registers first, and
 * registration order is route order. Adding an unrelated route that sorts earlier then swaps which
 * shape `SSOConnectionData` means, in a build that stays green — a silent breaking change to every
 * generated client, triggered by an edit that touched neither class.
 */
const SSO_INPUT = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Schema\\Authentication\\SSOConnectionData';
const SSO_OUTPUT = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Data\\SSO\\SSOConnectionData';
const SSO_LEGACY = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Legacy\\SSOConnectionData';

/**
 * The two SSO routes — the input shape arriving as a request body, the output one leaving as a
 * response — plus any extra routes named by action. Routes process in sorted `METHOD uri` order, so
 * `GET api/aaa-*` beats everything below and decides who registers first.
 *
 * @param  list<array{string, string, string}>  $extra  [method, uri, action]
 */
function ssoDocument(array $extra = []): GenerationResult
{
    $router = app('router');
    $router->post('api/zz-sso-a', [SsoController::class, 'store']);
    $router->get('api/zz-sso-b', [SsoController::class, 'show']);
    foreach ($extra as [$method, $uri, $action]) {
        $router->{$method}($uri, [SsoController::class, $action]);
    }

    $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(
        returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))],
    );

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        classOverrides: [
            SSO_INPUT => new ClassMetadata(SSO_INPUT, [
                new PropertyMetadata('issuerUrl', ScalarT::string()),
                new PropertyMetadata('clientSecret', ScalarT::string()),
            ]),
            SSO_OUTPUT => new ClassMetadata(SSO_OUTPUT, [
                new PropertyMetadata('issuerUrl', ScalarT::string()),
                new PropertyMetadata('verified', ScalarT::bool()),
            ]),
            SSO_LEGACY => new ClassMetadata(SSO_LEGACY, [
                new PropertyMetadata('issuerUrl', ScalarT::string()),
            ]),
        ],
        analysisOverrides: [
            SsoController::class.'::show' => $returns(SSO_OUTPUT),
            SsoController::class.'::unrelated' => $returns(SSO_INPUT),
            SsoController::class.'::legacy' => $returns(SSO_LEGACY),
        ],
    ));

    return generateDocument();
}

/**
 * @return array<string, array<string, mixed>> the document's `components.schemas`
 */
function ssoSchemas(GenerationResult $result): array
{
    $schemas = $result->document->toArray()['components']['schemas'] ?? [];

    return is_array($schemas) ? $schemas : [];
}

it('publishes both shapes under names that say which is which', function (): void {
    $schemas = ssoSchemas(ssoDocument());

    // The contested plain name is retired, not awarded — nothing can be reading it and getting the
    // wrong shape a build later.
    expect($schemas)->toHaveKeys(['AuthenticationSSOConnectionData', 'SSOSSOConnectionData'])
        ->and($schemas)->not->toHaveKey('SSOConnectionData')
        ->and($schemas)->not->toHaveKey('SSOConnectionData_2')
        ->and(array_keys($schemas['AuthenticationSSOConnectionData']['properties'] ?? []))->toBe(['issuerUrl', 'clientSecret'])
        ->and(array_keys($schemas['SSOSSOConnectionData']['properties'] ?? []))->toBe(['issuerUrl', 'verified']);
});

it('keeps each reference site pointing at its own shape, request side and response side', function (): void {
    $doc = ssoDocument()->document->toArray();

    $request = $doc['paths']['/api/zz-sso-a']['post']['requestBody']['content']['application/json']['schema']['properties']['connection'] ?? null;
    $response = $doc['paths']['/api/zz-sso-b']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($request['$ref'] ?? null)->toBe('#/components/schemas/AuthenticationSSOConnectionData')
        ->and($response['$ref'] ?? null)->toBe('#/components/schemas/SSOSSOConnectionData');
});

it('does not move an existing component when an unrelated route is added', function (): void {
    // `GET api/aaa-unrelated` sorts before both SSO routes and reaches the INPUT shape, so it flips
    // which class registers first. Under a positional suffix that alone would swap the two published
    // names. Here the two documents' components are byte-identical.
    $before = ssoSchemas(ssoDocument());
    $after = ssoSchemas(ssoDocument([['get', 'api/aaa-unrelated', 'unrelated']]));

    expect($after['AuthenticationSSOConnectionData'] ?? null)->toBe($before['AuthenticationSSOConnectionData'] ?? null)
        ->and($after['SSOSSOConnectionData'] ?? null)->toBe($before['SSOSSOConnectionData'] ?? null)
        ->and($after)->not->toHaveKey('SSOConnectionData');
});

it('names a third claimant off its own namespace too', function (): void {
    $schemas = ssoSchemas(ssoDocument([['get', 'api/zz-sso-c', 'legacy']]));

    expect($schemas)->toHaveKeys(['AuthenticationSSOConnectionData', 'SSOSSOConnectionData', 'LegacySSOConnectionData'])
        ->and(array_keys($schemas['LegacySSOConnectionData']['properties'] ?? []))->toBe(['issuerUrl']);
});

it('warns once, naming every class and the name it was published under', function (): void {
    $warnings = array_values(array_filter(
        diagnosticsCoded(ssoDocument()->diagnostics, 'components.name-collision'),
        static fn ($d): bool => str_contains($d->message, 'SSOConnectionData'),
    ));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->severity->value)->toBe('warning')
        ->and($warnings[0]->message)
        ->toContain('"SSOConnectionData"')
        ->toContain(SSO_INPUT.' as "AuthenticationSSOConnectionData"')
        ->toContain(SSO_OUTPUT.' as "SSOSSOConnectionData"')
        ->and($warnings[0]->help)->toContain('#[SchemaName]');
});

it('publishes the same names, and the same warning, on a warm fragment-cache build', function (): void {
    // The names are settled from the finished registry, which a warm hit restores — so unlike a
    // registration-time report, neither the names nor the warning can go missing when no route runs.
    $dir = sys_get_temp_dir().'/docuccino-sso-'.uniqid('', true);
    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $dir);

    $cold = ssoDocument();
    $warm = ssoDocument();

    expect((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and(diagnosticsCoded($warm->diagnostics, 'components.name-collision'))
        ->toEqual(diagnosticsCoded($cold->diagnostics, 'components.name-collision'))
        ->not->toBeEmpty();

    array_map('unlink', glob($dir.'/*') ?: []);
    @unlink($dir.'/.gitignore');
    @rmdir($dir);
});

it('keeps a warm fragment pointing at its own shape when a new route takes the name it cached', function (): void {
    // The nastiest form of the defect. A cached fragment recorded `$ref: SSOConnectionData`; a route
    // added since registers the OTHER class under that name first, so the restored operation would
    // silently point at the wrong shape — and the document would still be internally consistent.
    $dir = sys_get_temp_dir().'/docuccino-sso-warm-'.uniqid('', true);
    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $dir);

    ssoDocument();
    $grown = ssoDocument([['get', 'api/aaa-unrelated', 'unrelated']]);

    $doc = $grown->document->toArray();
    $schemas = ssoSchemas($grown);

    expect($doc['paths']['/api/zz-sso-b']['get']['responses']['200']['content']['application/json']['schema']['$ref'] ?? null)
        ->toBe('#/components/schemas/SSOSSOConnectionData')
        ->and(array_keys($schemas['SSOSSOConnectionData']['properties'] ?? []))->toBe(['issuerUrl', 'verified']);

    array_map('unlink', glob($dir.'/*') ?: []);
    @unlink($dir.'/.gitignore');
    @rmdir($dir);
});
