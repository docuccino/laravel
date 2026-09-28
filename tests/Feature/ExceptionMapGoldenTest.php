<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Workbench\App\Http\Controllers\FormController;

/**
 * The document an application gets when `$exceptions->map()` translates its errors before they render: a
 * missing model — thrown by the action and by the route's binding alike — is published as the 402 its
 * closure mapper builds, a conflict as the 403 a class-string entry names it into, and a third entry whose
 * mapper this build cannot read leaves its throw as thrown and says so. Byte-locked, warm as well as cold,
 * so the warning a cold build raises is one a warm build raises too.
 */
it('publishes each mapped throw as the exception it is translated to, byte-identically', function (): void {
    setBuild('documents.default.routes.include', ['api/*']);

    $mapper = registerExceptionMap(
        ModelNotFoundException::class,
        static fn (ModelNotFoundException $e) => new HttpException(402, $e->getMessage()),
        ModelNotFoundException::class,
    );
    registerExceptionMap(ConflictHttpException::class, AuthorizationException::class, ConflictHttpException::class);
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
        ],
    );

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        [
            $mapper => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT(HttpException::class), new SourceLocation(''))],
                throws: [new ThrownException(HttpException::class, 402, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
            ),
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
        ->and($statuses('/api/mapped-forms'))->toBe(['200', '401', '403'])
        ->and($warned)->toHaveCount(1)
        ->and($warned[0]->message)->toContain('Illuminate\\Auth\\AuthenticationException');
});
