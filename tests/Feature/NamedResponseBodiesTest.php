<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Tests\Fixtures\NamedBodies\DescribedBodiesController;
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

/**
 * `schemaDescription:` describes the schema a `schemaName:` names. Every declaration naming one schema is a
 * statement about the same thing, so the sentence settles over the whole document: written on one of them,
 * or alike on each, it is the schema's description; two different sentences describe nothing and say so,
 * rather than publishing whichever route was read last.
 */
function describedBodyRoutes(): callable
{
    return static function (Router $router): void {
        foreach (['verifyCode', 'verifyLink', 'secondFactor', 'secondFactorAgain', 'recover', 'recoverByEmail', 'verifyEmail'] as $action) {
            $router->post('api/described/'.$action, [DescribedBodiesController::class, $action]);
        }
    };
}

it('describes a named schema with the sentence its declarations agree on, and with none where they disagree', function (): void {
    $result = localityBuild(describedBodyRoutes());
    $schemas = emittedArray($result)['components']['schemas'];

    $conflicts = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'components.schema-description-conflict'));

    expect($schemas['SignInChallenge']['description'] ?? null)->toBe('Sign-in did not complete: one further step is required.')
        ->and($schemas['SecondFactorChallenge']['description'] ?? null)->toBe('The code was accepted, and a second factor is needed as well.')
        ->and($schemas['RecoveryChallenge'])->not->toHaveKey('description')
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]->message)->toContain('"Enter a recovery code." (POST /api/described/recover 409)')
        ->and($conflicts[0]->message)->toContain('"A recovery code is required to continue." (POST /api/described/recoverByEmail 409)');
});

it('reports a schema description with no schema name beside it, which describes nothing', function (): void {
    $result = localityBuild(describedBodyRoutes());

    $reported = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'attribute.response-schema-description-unnamed'));

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->routeSignature)->toBe('POST /api/described/verifyEmail')
        ->and(json_encode(emittedArray($result)))->not->toContain('An email challenge.');
});

it('leaves no description fact on a response once it is settled', function (): void {
    $document = emittedArray(localityBuild(describedBodyRoutes()));

    expect(json_encode($document))->not->toContain('"schemaDescriptions"');
});

it('describes and reports alike on a warm build', function (): void {
    $warm = assertWarmEqualsCold(describedBodyRoutes(), describedBodyRoutes());

    expect(emittedArray($warm)['components']['schemas']['SignInChallenge']['description'] ?? null)
        ->toBe('Sign-in did not complete: one further step is required.')
        ->and(array_filter($warm->diagnostics, static fn ($d): bool => $d->code === 'components.schema-description-conflict'))->toHaveCount(1);
});
