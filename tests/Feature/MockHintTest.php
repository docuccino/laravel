<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\MockHints\ProfileController;
use Docuccino\Laravel\Tests\Fixtures\MockHints\ProfileData;
use Docuccino\Laravel\Tests\Fixtures\MockHints\ProfileRequest;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/**
 * `#[Mock]` end to end through a real build: a hint reaches `x-docuccino.mock` on the schema its
 * property publishes, on the response side and the request side alike, and everything the attribute
 * cannot publish reaches a diagnostic instead of the document.
 */

/** @return callable(): TypeEngine */
function mockHintEngine(): callable
{
    return static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            ProfileData::class => new ClassMetadata(ProfileData::class, [
                new PropertyMetadata('id', ScalarT::string()),
                new PropertyMetadata('email', ScalarT::string()),
                new PropertyMetadata('nickname', ScalarT::string()),
            ]),
        ],
        analysisOverrides: [
            ProfileController::class.'::show' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT(ProfileData::class), new SourceLocation(''))],
            ),
            ProfileRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                new ArrayShapeField('email', new LiteralT('required|email')),
                new ArrayShapeField('per_page', new LiteralT('required|integer')),
            ]), new SourceLocation(''))]),
        ],
    );
}

/** @param  callable(Router): void  $routes */
function buildWithMockHints(callable $routes): array
{
    $result = localityBuild($routes, mockHintEngine());

    return [emittedArray($result), $result->diagnostics];
}

$responseRoute = static function (Router $r): void {
    $r->get('api/zz-profile', [ProfileController::class, 'show']);
};

$requestRoutes = static function (Router $r): void {
    $r->post('api/zz-profile', [ProfileController::class, 'store']);
    $r->get('api/zz-profiles', [ProfileController::class, 'index']);
};

it('publishes a property\'s hint on the component the response schema $refs', function () use ($responseRoute): void {
    [$document] = buildWithMockHints($responseRoute);

    expect($document['components']['schemas']['ProfileData']['properties'])->toBe([
        // `x-docuccino` leads each schema, which is the canonical order the UIR fixes.
        'email' => ['x-docuccino' => ['mock' => ['faker' => 'safeEmail', 'seedGroup' => 'profile']], 'type' => 'string'],
        'id' => ['x-docuccino' => ['mock' => ['faker' => 'uuid', 'seedGroup' => 'profile']], 'type' => 'string'],
        // The attribute carried nothing, so the property publishes exactly as it would without one.
        'nickname' => ['type' => 'string'],
    ]);
});

it('publishes a class-level hint on a validated field, in the body and in the query string alike', function () use ($requestRoutes): void {
    [$document] = buildWithMockHints($requestRoutes);

    $body = $document['components']['schemas']['ProfileRequest']['properties'];

    expect($body['email']['x-docuccino']['mock'])->toBe(['faker' => 'safeEmail'])
        ->and($body['per_page']['x-docuccino']['mock'])->toBe(['faker' => 'numberBetween:1,100', 'seedGroup' => 'listing']);

    // The read verb flattens the same fields to parameters, and the hint follows the field.
    $parameters = $document['paths']['/api/zz-profiles']['get']['parameters'];
    $perPage = current(array_filter($parameters, static fn (array $p): bool => $p['name'] === 'per_page'));

    expect($perPage['schema']['x-docuccino']['mock'])->toBe(['faker' => 'numberBetween:1,100', 'seedGroup' => 'listing']);
});

it('reports every #[Mock] that publishes nothing, and puts none of it in the document', function () use ($responseRoute, $requestRoutes): void {
    // The whole firing population: an attribute the author wrote that cannot land. Both rows below are
    // actionable at the line they name — fill the attribute in, fix the field name, or delete it.
    [$document, $diagnostics] = buildWithMockHints(static function (Router $r) use ($responseRoute, $requestRoutes): void {
        $responseRoute($r);
        $requestRoutes($r);
    });

    $mockDiagnostics = array_values(array_filter(
        $diagnostics,
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'attribute.mock-'),
    ));

    expect(array_unique(array_map(static fn (Diagnostic $d): string => $d->code.'|'.$d->message, $mockDiagnostics)))
        ->toEqualCanonicalizing([
            'attribute.mock-invalid|#[Mock] on '.ProfileData::class.'::$nickname carries no faker expression and no seed group; it is ignored.',
            'attribute.mock-unknown-property|#[Mock(property: \'gone\')] on class '.ProfileRequest::class.' names a property the schema does not publish; the hint is dropped.',
        ]);

    // Nothing about the author's tooling reaches the consumer's copy.
    expect(json_encode($document))->not->toContain('#[Mock]')
        ->and($document['components']['schemas']['ProfileRequest']['properties'])->not->toHaveKey('gone');
});

it('serves a warm build exactly what a cold one would, hints and diagnostics both', function () use ($responseRoute, $requestRoutes): void {
    // A hint is read while a fragment is built, and its complaints are raised there too — so a warm hit
    // that reassembles rather than rebuilds has to replay both or the cache is a silent degradation.
    $warm = assertWarmEqualsCold(
        $requestRoutes,
        static function (Router $r) use ($responseRoute, $requestRoutes): void {
            $requestRoutes($r);
            $responseRoute($r);
        },
        mockHintEngine(),
    );

    // …and it really did replay them, rather than both builds being equally silent.
    expect(array_filter($warm->diagnostics, static fn (Diagnostic $d): bool => str_starts_with($d->code, 'attribute.mock-')))
        ->not->toBeEmpty()
        ->and($warm->document->toArray()['components']['schemas']['ProfileRequest']['properties']['email']['x-docuccino']['mock'])
        ->toBe(['faker' => 'safeEmail']);
});

it('publishes hints in an OpenAPI artifact only when export.mock_faker_key names a member', function (?string $key, bool $expected) use ($responseRoute): void {
    // The UIR carries the hint either way; this decides whether the OpenAPI artifact does. A consumer
    // of a bare export gets pure OpenAPI, which is why the default is to drop them.
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $responseRoute($router);
    app()->instance(TypeEngine::class, mockHintEngine()());

    if ($key !== null) {
        config()->set('docuccino.documents.default.export.mock_faker_key', $key);
    }

    $out = sys_get_temp_dir().'/docuccino-mock-'.uniqid().'.json';
    test()->artisan('docuccino:export', ['--format' => 'openapi-3.2', '--out' => $out])->assertSuccessful();
    $artifact = (string) file_get_contents($out);
    @unlink($out);

    expect(str_contains($artifact, '"x-faker": "safeEmail"'))->toBe($expected)
        // Whatever the setting, the hint's own container never reaches an OpenAPI artifact.
        ->and($artifact)->not->toContain('x-docuccino": {');
})->with([
    'unset drops them' => [null, false],
    'the conventional member' => ['x-faker', true],
]);
