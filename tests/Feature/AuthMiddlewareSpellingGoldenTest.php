<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Support\AuthMiddlewareNames;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * The two spellings of the authentication middleware, side by side under byte-lock: `auth:web`, and the
 * `Illuminate\Auth\Middleware\Authenticate:web` that `Authenticate::using('web')` renders. Both feed the
 * one signal that decides the implicit 401 AND the per-operation security requirement — what a reader of
 * one spelling costs is stated in {@see AuthMiddlewareNames}.
 *
 * `auth_middleware` + a `security.default` + error responses is the population that decides both
 * of those facts, and no committed document stood in it, which is why nothing said so.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    /** @var Router $router */
    $router = app('router');
    $router->get('api/spelling/by-alias', [FormController::class, 'index'])->middleware('auth:web');
    $router->get('api/spelling/by-class', [FormController::class, 'index'])->middleware(Authenticate::using('web'));

    setDocuments([
        'spelling' => [
            'info' => ['title' => 'Auth Spelling', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/spelling/*']],
            'error_responses' => 'default',
            'security' => [
                'schemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                'auth_middleware' => 'auth*',
                'default' => [['bearer' => []]],
            ],
        ],
    ]);
});

function spellingDocument(): array
{
    /** @var array<string, mixed> $raw */
    $raw = documentSettings('spelling');
    $config = app(DocumentConfigFactory::class)->make('spelling', $raw, 'skeleton');

    return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();
}

it('emits both spellings of the authenticator byte-identical to its golden', function (): void {
    assertGolden('workbench-auth-spelling.uir.json', (new UirEmitter)->emit(UirDocument::fromArray(spellingDocument())));
});

/**
 * The equivalence itself, stated over the WHOLE operation rather than over the two facts a regression
 * would take away: asserting the 401 and the requirement separately passes on a reader that answers the
 * two spellings differently in some third respect. Only the path-derived identities differ, so they are
 * the only thing dropped before the comparison.
 */
it('publishes the same operation for either spelling of the same middleware', function (): void {
    $paths = spellingDocument()['paths'];

    $strip = function (mixed $node) use (&$strip): mixed {
        if (! is_array($node)) {
            return $node;
        }

        unset($node['operationId']);
        if (is_array($node['x-docuccino'] ?? null)) {
            unset($node['x-docuccino']['id']);
        }

        return array_map($strip, $node);
    };

    expect($strip($paths['/api/spelling/by-class']['get']))
        ->toBe($strip($paths['/api/spelling/by-alias']['get']));
});

/** And the two facts named, so a failure says which half of the contract went. */
it('carries the implicit 401 and the default security requirement for the class spelling', function (): void {
    $operation = spellingDocument()['paths']['/api/spelling/by-class']['get'];

    expect(array_map(strval(...), array_keys($operation['responses'])))->toContain('401')
        ->and($operation['security'])->toBe([['bearer' => []]]);
});
