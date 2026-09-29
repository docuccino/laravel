<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\DeclaredErrorsController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An `HttpException` whose status the code states — a subclass pinning `parent::__construct(501, …)`, an
 * `abort(502)` — is rendered by the framework exactly as its own exceptions are, so it is described by its
 * status's registered reason phrase and its shared body is named after it. What an HTTP status is called
 * is the IANA registry's answer, not a list of the statuses Laravel happens to ship an exception for.
 */
/*
 * Two routes throwing the same errors, so every body repeats and is hoisted. The NotFoundHttpException is
 * the framework's own, for the comparison: both are rendered by `convertExceptionToArray()`.
 */
beforeEach(function (): void {
    $throws = new ActionAnalysis(throws: array_map(
        static fn (array $pair): ThrownException => new ThrownException($pair[0], $pair[1], [], ThrowConfidence::Certain, ThrowDisposition::Signal),
        [
            [NotFoundHttpException::class, null],
            [HttpException::class, 402],
            [HttpException::class, 408],
            [HttpException::class, 419],
            [HttpException::class, 501],
            [HttpException::class, 502],
            [HttpException::class, 504],
            [HttpException::class, 599],
        ],
    ));

    setBuild('documents.default.routes.include', ['api/zz-phrase-*']);

    $this->routes = static function (Router $router): void {
        $router->get('api/zz-phrase-first', [DeclaredErrorsController::class, 'first']);
        $router->get('api/zz-phrase-second', [DeclaredErrorsController::class, 'second']);
    };
    $this->engine = static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: [
        DeclaredErrorsController::class.'::first' => $throws,
        DeclaredErrorsController::class.'::second' => $throws,
    ]);
});

afterEach(function (): void {
    removeFragmentCacheDirs('warm');
    removeFragmentCacheDirs('cold');
});

it('describes and names an error at any registered status as the framework\'s own are', function (): void {
    $warm = assertWarmEqualsCold($this->routes, $this->routes, $this->engine);
    $document = $warm->document->toArray();

    // Stated from the registry, not read back off the code: each status's IANA phrase describes it, and the
    // phrase as one word names what a client catches.
    $expected = [
        '402' => ['Payment Required', 'PaymentRequired'],
        '404' => ['Not Found', 'NotFound'],
        '408' => ['Request Timeout', 'RequestTimeout'],
        '501' => ['Not Implemented', 'NotImplemented'],
        '502' => ['Bad Gateway', 'BadGateway'],
        '504' => ['Gateway Timeout', 'GatewayTimeout'],
        // Unregistered: described by the class RFC 9110 §15 files it under, and named by its number,
        // because the class is shared by every unregistered code in it and would be contested.
        '419' => ['Client Error', 'Error419'],
        '599' => ['Server Error', 'Error599'],
    ];

    $responses = $document['paths']['/api/zz-phrase-first']['get']['responses'];
    foreach ($expected as $status => [$description, $name]) {
        expect($responses[$status]['$ref'] ?? null)->toBe('#/components/responses/'.$name)
            ->and($document['components']['responses'][$name]['description'])->toBe($description)
            ->and($document['components']['responses'][$name]['content']['application/json']['schema']['$ref'])
            ->toBe('#/components/schemas/'.$name);

        // Laravel's JSON renderer writes `message` for every exception it renders, an `HttpException` of
        // any status included, so the body a status-stating throw publishes says so as the framework's
        // own 404 does.
        $schema = $document['components']['schemas'][$name];
        expect($schema['properties'])->toBe(['message' => ['type' => 'string']])
            ->and($schema['required'])->toBe(['message']);
    }

    expect(array_keys($responses))->toEqualCanonicalizing(array_map(strval(...), array_keys($expected)));

    assertGolden('workbench-error-reason-phrases.uir.json', (new UirEmitter)->emit($warm->document));
});

it('publishes one component for the framework\'s error and a thrown status that renders the same body', function (): void {
    // A `NotFoundHttpException` and an `HttpException(404)` go through one renderer and send one body. Both
    // tiers claim `NotFound` for it; were their bodies to differ by a `required`, the two claims would be two
    // contracts contesting one name, and both would be retired into content hashes.
    $throws = static fn (string $fqcn, ?int $status): ActionAnalysis => new ActionAnalysis(throws: [
        new ThrownException($fqcn, $status, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
    ]);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(analysisOverrides: [
        DeclaredErrorsController::class.'::first' => $throws(NotFoundHttpException::class, null),
        DeclaredErrorsController::class.'::second' => $throws(NotFoundHttpException::class, null),
        DeclaredErrorsController::class.'::third' => $throws(HttpException::class, 404),
        DeclaredErrorsController::class.'::fourth' => $throws(HttpException::class, 404),
    ]));

    /** @var Router $router */
    $router = app('router');
    foreach (['first', 'second', 'third', 'fourth'] as $action) {
        $router->get('api/zz-phrase-'.$action, [DeclaredErrorsController::class, $action]);
    }

    $result = generateDocument();
    $document = $result->document->toArray();

    foreach (['first', 'second', 'third', 'fourth'] as $action) {
        expect($document['paths']['/api/zz-phrase-'.$action]['get']['responses']['404']['$ref'] ?? null)
            ->toBe('#/components/responses/NotFound');
    }

    expect(array_keys($document['components']['schemas']))->toBe(['NotFound'])
        ->and(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'components.name-collision'))->toBe([]);
});
