<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\SignedLinkController;
use Workbench\App\Http\Middleware\ValidateLinkSignature;

/**
 * A route behind the framework's signed-URL middleware, in every spelling a route can name it. The
 * middleware rejects a request with no `signature` query parameter, and rejects an `expires` in the past
 * when one is present (`UrlGenerator::hasValidSignature()`), so both are part of what a request to the
 * route has to carry — as much as its path parameters are. The document already published the 403 the
 * middleware raises; without the parameters it said a request may be refused and never said what to
 * send to avoid it.
 */
beforeEach(function (): void {
    refreshWithoutHttpKernel();
    $confirmed = new ActionAnalysis(returns: [new ReturnSite(
        new ArrayShapeT([new ArrayShapeField('confirmed', ScalarT::bool())]),
        new SourceLocation(''),
    )]);
    app()->instance(TypeEngine::class, WorkbenchEngine::make(analysisOverrides: [
        SignedLinkController::class.'::confirm' => $confirmed,
        SignedLinkController::class.'::confirmExpiring' => $confirmed,
        SignedLinkController::class.'::confirmWithoutExpiry' => $confirmed,
    ]));

    registerAppMiddlewareGroup('docuccino-signed-links', ['signed:relative']);
    // An application's own names for the middleware, which reach the route only through the alias map.
    registerAppMiddlewareAliases([
        'signed.link' => ValidateSignature::class,
        'signed.app' => ValidateLinkSignature::class,
    ]);

    /** @var Router $router */
    $router = app('router');
    $router->get('api/signed-links/by-alias/{token}', [SignedLinkController::class, 'confirm'])->middleware('signed');
    $router->get('api/signed-links/by-alias-relative/{token}', [SignedLinkController::class, 'confirm'])->middleware('signed:relative');
    $router->get('api/signed-links/by-class-relative/{token}', [SignedLinkController::class, 'confirm'])->middleware(ValidateSignature::relative());
    $router->get('api/signed-links/by-class-absolute/{token}', [SignedLinkController::class, 'confirm'])->middleware(ValidateSignature::absolute(['utm_source']));
    $router->get('api/signed-links/by-subclass/{token}', [SignedLinkController::class, 'confirm'])->middleware(ValidateLinkSignature::relative());
    $router->get('api/signed-links/by-custom-alias/{token}', [SignedLinkController::class, 'confirm'])->middleware('signed.link');
    $router->get('api/signed-links/by-custom-alias-to-subclass/{token}', [SignedLinkController::class, 'confirm'])->middleware('signed.app:relative');
    $router->get('api/signed-links/from-group/{token}', [SignedLinkController::class, 'confirm'])->middleware('docuccino-signed-links');
    $router->get('api/signed-links/expiring/{token}', [SignedLinkController::class, 'confirmExpiring'])->middleware('signed');
    $router->get('api/signed-links/without-expiry/{token}', [SignedLinkController::class, 'confirmWithoutExpiry'])->middleware('signed');
    // The anti-vacuity row: the same action with no signature check owes neither parameter.
    $router->get('api/signed-links/unsigned/{token}', [SignedLinkController::class, 'confirm']);
    $router->getRoutes()->refreshNameLookups();

    setDocuments([
        'signed' => [
            'info' => ['title' => 'Signed Links', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/signed-links/*']],
            'error_responses' => 'default',
        ],
    ]);

    $this->signedPaths = static fn (): array => generateDocument(key: 'signed')->document->toArray()['paths'];
});

it('emits every spelling of the signed middleware byte-identical to its golden', function (): void {
    $document = generateDocument(key: 'signed')->document->toArray();

    assertGolden('workbench-signed-links.uir.json', (new UirEmitter)->emit(UirDocument::fromArray($document)));
});

/**
 * The contract, stated from the framework rather than from this change: `hasCorrectSignature()` refuses
 * a request whose `signature` is not a string, so it is required; `signatureHasNotExpired()` passes a
 * request with no `expires` at all — `URL::signedRoute()` mints links without one — so it is optional,
 * and the value a link carries is the integer timestamp `availableAt()` returns.
 */
it('publishes signature as a required string and expires as an optional integer', function (string $path): void {
    $parameters = paramsByName(($this->signedPaths)()[$path]['get']);

    expect($parameters['signature']['in'])->toBe('query')
        ->and($parameters['signature']['required'])->toBeTrue()
        ->and($parameters['signature']['schema']['type'])->toBe('string')
        ->and($parameters['expires']['in'])->toBe('query')
        ->and($parameters['expires']['required'] ?? false)->toBeFalse()
        ->and($parameters['expires']['schema']['type'])->toBe('integer')
        ->and($parameters)->toHaveKey('token');
})->with([
    'signed' => ['/api/signed-links/by-alias/{token}'],
    'signed:relative' => ['/api/signed-links/by-alias-relative/{token}'],
    'ValidateSignature::relative()' => ['/api/signed-links/by-class-relative/{token}'],
    'ValidateSignature::absolute() with an ignored parameter' => ['/api/signed-links/by-class-absolute/{token}'],
    "an application's own subclass" => ['/api/signed-links/by-subclass/{token}'],
    'inherited from a middleware group' => ['/api/signed-links/from-group/{token}'],
    "an application's own alias" => ['/api/signed-links/by-custom-alias/{token}'],
    "an application's own alias for its subclass" => ['/api/signed-links/by-custom-alias-to-subclass/{token}'],
]);

/**
 * Every spelling publishes the same operation: the parameters AND the 403 read one predicate, so a
 * spelling that earned one of them and not the other would show up here as a difference.
 */
it('publishes one operation whatever spelling the route used', function (): void {
    $paths = ($this->signedPaths)();

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

    $reference = $strip($paths['/api/signed-links/by-alias/{token}']['get']);
    foreach (['by-alias-relative', 'by-class-relative', 'by-class-absolute', 'by-subclass', 'from-group', 'by-custom-alias', 'by-custom-alias-to-subclass'] as $spelling) {
        expect($strip($paths['/api/signed-links/'.$spelling.'/{token}']['get']))->toBe($reference, $spelling);
    }

    expect(array_map(strval(...), array_keys($reference['responses'])))->toContain('403');
});

it('lets a #[QueryParameter] tighten expires to required', function (): void {
    $expires = paramsByName(($this->signedPaths)()['/api/signed-links/expiring/{token}']['get'])['expires'];

    expect($expires['required'])->toBeTrue()
        ->and($expires['schema']['type'])->toBe('integer');
});

it('drops a parameter an #[IgnoreParam] names, and keeps the other', function (): void {
    $parameters = paramsByName(($this->signedPaths)()['/api/signed-links/without-expiry/{token}']['get']);

    expect($parameters)->not->toHaveKey('expires')
        ->and($parameters)->toHaveKey('signature');
});

it('publishes neither parameter, nor the 403, on a route with no signature check', function (): void {
    $operation = ($this->signedPaths)()['/api/signed-links/unsigned/{token}']['get'];

    expect(array_keys(paramsByName($operation)))->toBe(['token'])
        ->and(array_map(strval(...), array_keys($operation['responses'])))->not->toContain('403');
});

/**
 * The alias is the application's to re-point: the framework resolves `signed` through the map, so a route
 * whose `signed` runs some other middleware is not signature-checked and owes neither fact.
 */
it('publishes neither parameter, nor the 403, where the application re-pointed the signed alias', function (): void {
    registerAppMiddlewareAliases(['signed' => ValidatePostSize::class]);

    $operation = ($this->signedPaths)()['/api/signed-links/by-alias/{token}']['get'];

    expect(array_keys(paramsByName($operation)))->toBe(['token'])
        ->and(array_map(strval(...), array_keys($operation['responses'])))->not->toContain('403');
});

/**
 * The alias map is now an input to the document, so it owes the fragment cache both halves: a warm build
 * equals a cold one, and re-pointing an alias re-reads exactly the route that names it — the route's
 * own middleware string does not change, so nothing else would.
 */
it('serves a warm build equal to the cold one and re-reads the route whose alias was re-pointed', function (): void {
    fragmentCacheDir('signed-fragments');
    $engine = new CountingTypeEngine(app(TypeEngine::class));
    app()->instance(TypeEngine::class, $engine);

    $cold = generateDocument(key: 'signed');
    $coldBytes = (new UirEmitter)->emit($cold->document);

    $engine->analyzeCount = 0;
    expect((new UirEmitter)->emit(generateDocument(key: 'signed')->document))->toBe($coldBytes)
        ->and($engine->analyzeCount)->toBe(0);

    app('router')->aliasMiddleware('signed.link', ValidatePostSize::class);
    $engine->analyzeCount = 0;
    $operation = generateDocument(key: 'signed')->document->toArray()['paths']['/api/signed-links/by-custom-alias/{token}']['get'];

    expect($engine->analyzeCount)->toBe(1)
        ->and(array_keys(paramsByName($operation)))->toBe(['token']);
});

afterEach(function (): void {
    removeFragmentCacheDirs('signed-fragments');
});
