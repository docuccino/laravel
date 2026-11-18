<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Tests\Fixtures\NamedBodies\NamedBodiesController;
use Illuminate\Routing\Router;

/**
 * `#[Response(schemaName:)]` names the body a response declares — the one name a union, or an envelope a
 * wrap writes, can be given, since neither is a class `#[SchemaName]` could annotate. Without it a body
 * like this at an error status is hoisted under its status, and two of them at one status are told apart
 * by a content hash: a generated client catches `Error422Oikgfln5`.
 */
function namedBodyRoutes(): callable
{
    return static function (Router $router): void {
        $router->post('api/sign-in/code', [NamedBodiesController::class, 'verifyCode']);
        $router->post('api/sign-in/link', [NamedBodiesController::class, 'verifyLink']);
        $router->post('api/sign-in/email', [NamedBodiesController::class, 'verifyEmail']);
    };
}

afterEach(function (): void {
    $path = app(BuildConfig::class)->raw('cache.path');
    if (is_string($path)) {
        removeFragmentCacheDir($path);
    }
});

it('publishes a declared body under the schema name its author gave it, one component for one body', function (): void {
    $document = emittedArray(localityBuild(namedBodyRoutes()));
    // Two operations stating one 422 share the response too, so the body is read through its `$ref`.
    $body = static function (string $path) use ($document): mixed {
        $response = $document['paths'][$path]['post']['responses']['422'];
        $ref = $response['$ref'] ?? null;
        $response = is_string($ref) ? $document['components']['responses'][substr($ref, strlen('#/components/responses/'))] : $response;

        return $response['content']['application/json']['schema'] ?? null;
    };

    expect($body('/api/sign-in/code'))->toBe(['$ref' => '#/components/schemas/SignInChallenge'])
        ->and($body('/api/sign-in/link'))->toBe(['$ref' => '#/components/schemas/SignInChallenge'])
        ->and($document['components']['schemas']['SignInChallenge'])->toHaveKey('anyOf')
        ->and(array_filter(array_keys($document['components']['schemas']), static fn (string $name): bool => str_starts_with($name, 'Error422')))->toBe([]);
});

it('names nothing with a schema name no component key can carry, and says so', function (): void {
    $result = localityBuild(namedBodyRoutes());
    $document = emittedArray($result);

    $reported = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'attribute.response-schema-name-invalid'));

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->routeSignature)->toBe('POST /api/sign-in/email')
        ->and($document['components']['schemas'] ?? [])->not->toHaveKey('Sign In/Challenge');
});

it('serves the named body alike on a warm build', function (): void {
    $warm = assertWarmEqualsCold(namedBodyRoutes(), namedBodyRoutes());

    expect(emittedArray($warm)['components']['schemas'])->toHaveKey('SignInChallenge');
});
