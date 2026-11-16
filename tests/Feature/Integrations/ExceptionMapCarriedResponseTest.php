<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\PublishArticleAction;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An exception map answering with an `HttpResponseException`: `Handler::render()` sends the response it
 * carries as it is, so every response the mapped class stood for is that response — its status, its body
 * — and never the framework error the class would otherwise render to. Each producer that asks the
 * error-response chain is one row; the mapper's analysis is the real engine reading the fixture app's own
 * mapper, and the document around it is the full pipeline.
 *
 * The rate limit's 429 is no row: it publishes whatever body the chain answers under its own status and
 * throttle headers, so a response sent at another status is not one it can document.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The real engine's reading of the fixture mapper answering with `response()->json([...], 423)`. */
function carriedMapperAnalysis(string $narrowTo): ActionAnalysis
{
    $source = (string) file_get_contents(FixtureRunner::path('app/Exceptions/ExceptionMappers.php'));
    $line = 0;
    foreach (explode("\n", $source) as $index => $text) {
        if (str_contains($text, 'public function carriedResponse(')) {
            $line = $index + 3;
        }
    }

    return ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/ExceptionMappers.php',
        '',
        '',
        line: $line,
        param: 'e',
        narrowType: $narrowTo,
        returnsExceptions: true,
    ));
}

it('publishes the response the mapper carries for every producer that asks the chain', function (string $mapped, string $verb, string $path, string $framework, array $actions): void {
    /** @var Router $router */
    $router = app('router');
    $router->post('api/publish', PublishArticleAction::class);

    $symbol = registerExceptionMap(
        $mapped,
        static fn ($e) => new HttpResponseException(response()->json(['error' => 'gone_elsewhere'], 423)),
        $mapped,
    );
    app()->instance(TypeEngine::class, WorkbenchEngine::make([$symbol => carriedMapperAnalysis($mapped)], analysisOverrides: $actions));

    $document = generateDocument()->document->toArray();
    $responses = $document['paths'][$path][$verb]['responses'] ?? [];
    $carried = resolveResponse($document, $responses['423'] ?? null);
    $schema = $carried['content']['application/json']['schema'] ?? [];
    if (isset($schema['$ref'])) {
        $schema = $document['components']['schemas'][substr((string) $schema['$ref'], strlen('#/components/schemas/'))] ?? [];
    }

    // The server sends `423 {"error": "gone_elsewhere"}` in place of the framework's error, so that
    // response is documented and the status the class would have rendered at is not.
    expect(array_map('strval', array_keys($responses)))->toContain('423')->not->toContain($framework)
        ->and(array_keys($schema['properties'] ?? []))->toBe(['error']);
})->with([
    'a throw the action makes' => [ConflictHttpException::class, 'get', '/api/forms/{form}', '409', [
        'Workbench\\App\\Http\\Controllers\\FormController::show' => new ActionAnalysis(
            returns: [new ReturnSite(new ClassT('Workbench\\App\\Data\\FormData'), new SourceLocation(''))],
            throws: [new ThrownException(ConflictHttpException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
        ),
    ]],
    'a missing model the route binds' => [ModelNotFoundException::class, 'get', '/api/forms/{form}', '404', []],
    'a request the route validates' => [ValidationException::class, 'post', '/api/tickets', '422', []],
    'a gate the route\'s middleware checks' => [AuthorizationException::class, 'get', '/api/guarded-forms', '403', []],
    'a query Query Builder refuses' => ['Spatie\\QueryBuilder\\Exceptions\\InvalidQuery', 'get', '/api/widget-query', '400', []],
    'an action\'s authorize()' => [AuthorizationException::class, 'post', '/api/publish', '403', []],
])->group('fixture');
