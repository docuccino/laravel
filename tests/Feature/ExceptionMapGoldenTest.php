<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Workbench\App\Http\Controllers\FormController;

/**
 * The document an application gets when `$exceptions->map()` translates its errors before they render: a
 * missing model — thrown by the action and by the route's binding alike — is published as the 402 its
 * closure mapper builds, a conflict as the 403 a class-string entry names it into, a gone resource as the
 * response an `HttpResponseException` its mapper builds carries, and a fourth entry whose mapper this build
 * cannot read leaves its throw as thrown and says so. Byte-locked, warm as well as cold, so the warning a
 * cold build raises is one a warm build raises too.
 */

/** The mapper analysis the real engine gives the fixture app's `carriedResponse()` mapper. */
function carriedMapperScript(): ActionAnalysis
{
    return new ActionAnalysis(
        returns: [new ReturnSite(new ClassT(HttpResponseException::class), new SourceLocation(''))],
        throws: [new ThrownException(HttpResponseException::class, 500, [], ThrowConfidence::Certain, ThrowDisposition::Signal, new ReturnSite(
            new ClassT(JsonResponse::class, [
                new ArrayShapeT([new ArrayShapeField('error', new LiteralT('gone_elsewhere'))]),
                new LiteralT(423),
            ]),
            new SourceLocation(''),
        ))],
    );
}

it('publishes each mapped throw as the exception it is translated to, byte-identically', function (): void {
    setBuild('documents.default.routes.include', ['api/*']);

    $mapper = registerExceptionMap(
        ModelNotFoundException::class,
        static fn (ModelNotFoundException $e) => new HttpException(402, $e->getMessage()),
        ModelNotFoundException::class,
    );
    registerExceptionMap(ConflictHttpException::class, AuthorizationException::class, ConflictHttpException::class);
    $carried = registerExceptionMap(
        GoneHttpException::class,
        static fn ($e) => new HttpResponseException(response()->json(['error' => 'gone_elsewhere'], 423)),
        GoneHttpException::class,
    );
    $unread = registerExceptionMap(
        'Illuminate\\Auth\\AuthenticationException',
        static fn (Throwable $e) => $e->getPrevious() ?? $e,
        'Illuminate\\Auth\\AuthenticationException',
    );

    $listing = new ActionAnalysis(
        returns: [new ReturnSite(new ClassT('Workbench\\App\\Data\\FormData'), new SourceLocation(''))],
        throws: [
            new ThrownException('Illuminate\\Auth\\AuthenticationException', 401, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            new ThrownException(ConflictHttpException::class, null, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            new ThrownException(GoneHttpException::class, null, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
        ],
    );

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        [
            $mapper => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT(HttpException::class), new SourceLocation(''))],
                throws: [new ThrownException(HttpException::class, 402, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
            ),
            $carried => carriedMapperScript(),
            $unread => new ActionAnalysis(returns: [new ReturnSite(new UnknownT('mixed'), new SourceLocation(''))]),
        ],
        analysisOverrides: ['Workbench\\App\\Http\\Controllers\\FormController::index' => $listing],
    );

    $routes = static function (Router $router): void {
        $router->get('api/mapped-forms/{form}', [FormController::class, 'show']);
        $router->get('api/mapped-forms', [FormController::class, 'index']);
    };

    $warm = assertWarmEqualsCold($routes, $routes, $engine);
    $document = emittedArray($warm);

    assertGolden('workbench-exception-map.uir.json', (new UirEmitter)->emit($warm->document));

    $warned = array_values(array_filter(
        $warm->diagnostics,
        static fn (Diagnostic $d): bool => $d->code === 'inferred-handler.too-dynamic' && str_contains($d->message, 'exception map'),
    ));

    $statuses = static fn (string $path): array => array_map('strval', array_keys($document['paths'][$path]['get']['responses']));

    expect($statuses('/api/mapped-forms/{form}'))->toBe(['200', '402'])
        ->and($statuses('/api/mapped-forms'))->toBe(['200', '401', '403', '423'])
        ->and($warned)->toHaveCount(1)
        ->and($warned[0]->message)->toContain('Illuminate\\Auth\\AuthenticationException');
});

it('scripts what the real engine reads the fixture app\'s carrying mapper as', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $source = (string) file_get_contents(FixtureRunner::path('app/Exceptions/ExceptionMappers.php'));
    $line = 0;
    foreach (explode("\n", $source) as $index => $text) {
        if (str_contains($text, 'public function carriedResponse(')) {
            $line = $index + 3;
        }
    }

    $real = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/ExceptionMappers.php',
        '',
        '',
        line: $line,
        param: 'e',
        narrowType: GoneHttpException::class,
        returnsExceptions: true,
    ));

    // What the golden depends on: the class, status and carried response of each translation, and the
    // type of each return. Where each is written is the fixture's own file.
    $facts = static fn (ActionAnalysis $analysis): array => [
        array_map(static fn (ThrownException $throw): string => $throw->identityKey().' '.$throw->confidence->value.' '.$throw->disposition->value, $analysis->throws),
        array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $analysis->returns),
    ];

    expect($facts($real))->toBe($facts(carriedMapperScript()));
})->group('fixture');
