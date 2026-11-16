<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\StatusTextMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\InferredHandler\ReceivedException;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Workbench\App\Http\Controllers\FormController;
use Workbench\App\Http\Controllers\ValidationController;

/**
 * The document an application gets when its `$exceptions->respond()` hook answers every error with RFC
 * 9457's `about:blank` problem, titled with the reason phrase of the status it is sent with
 * (`Response::$statusTexts[$status] ?? 'Error'`): each error's example says what the server sends under
 * that status — the phrase for a status Symfony names, the `??` fallback for one it does not — while the
 * schema shared across statuses names no phrase. An object body (`new HttpProblem($response)`) and an
 * array one answer alike, and so do the bodies two factories build: one whose constructor runs its parent's
 * (`FieldProblem`), and one built before the rendered body is read for Laravel's other members
 * (`DebugProblem`). Two problem classes other problems extend, one built through a factory (`ErrorProblem`)
 * and one sending itself (`SentProblem`), say what their own constructors write. Byte-locked, warm as well as cold; the scripted analyses are what the real engine
 * recovers from the fixture app's own callbacks, which the last tests hold them to.
 */
/**
 * The members a problem's constructor reads off the rendered response it is sent with: its status, and the
 * phrase of it. Built by its class's own name, the problem also holds the `type` that constructor writes.
 */
function statusTextEchoes(): ArrayShapeT
{
    return new ArrayShapeT([
        new ArrayShapeField('status', new StatusMarkerT),
        new ArrayShapeField('title', new StatusTextMarkerT(ScalarT::string(), new LiteralT('Error'))),
        new ArrayShapeField('type', new LiteralT('about:blank')),
    ]);
}

function statusTextProblemObject(): ClassT
{
    return new ClassT('Illuminate\\Http\\JsonResponse', [
        new ClassT('App\\Problems\\HttpProblem'),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
        statusTextEchoes(),
    ]);
}

function statusTextProblemArray(): ClassT
{
    return new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([
            new ArrayShapeField('type', new LiteralT('about:blank')),
            new ArrayShapeField('title', new StatusTextMarkerT(ScalarT::string(), new LiteralT('Error'))),
            new ArrayShapeField('status', new StatusMarkerT),
            new ArrayShapeField('errors', new MapT(ScalarT::string(), new ListT(ScalarT::string())), optional: true),
        ]),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
    ]);
}

function statusTextFieldProblem(): ClassT
{
    return new ClassT('Illuminate\\Http\\JsonResponse', [
        new ClassT('App\\Problems\\FieldProblem'),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
        statusTextEchoes(),
    ]);
}

function statusTextDebugProblem(): ClassT
{
    return new ClassT('Illuminate\\Http\\JsonResponse', [
        new ClassT('App\\Problems\\DebugProblem'),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
        statusTextEchoes(),
    ]);
}

function statusTextErrorProblem(): ClassT
{
    return new ClassT('Illuminate\\Http\\JsonResponse', [
        new ClassT('App\\Problems\\ErrorProblem'),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
        statusTextEchoes(),
    ]);
}

function statusTextSentProblem(): ClassT
{
    return new ClassT('Illuminate\\Http\\JsonResponse', [
        new ClassT('App\\Problems\\SentProblem'),
        // Its own `toResponse()` sends the status it was built with, and it was built with 410.
        new LiteralT(410),
        new LiteralT('application/problem+json'),
        new ArrayShapeT([
            new ArrayShapeField('status', new LiteralT(410)),
            new ArrayShapeField('title', new LiteralT('Gone')),
            new ArrayShapeField('type', new LiteralT('about:blank')),
        ]),
    ]);
}

/** @return array<string, ClassMetadata> */
function statusTextProblemClasses(): array
{
    $fixed = static fn (): array => [
        new PropertyMetadata('type', new LiteralT('about:blank'), initialised: true),
        new PropertyMetadata('title', ScalarT::string(), initialised: true),
        new PropertyMetadata('status', ScalarT::int(), initialised: true),
    ];

    return [
        'App\\Problems\\HttpProblem' => statusTextProblemClass(),
        'App\\Problems\\FieldProblem' => new ClassMetadata('App\\Problems\\FieldProblem', [
            new PropertyMetadata('errors', new MapT(ScalarT::string(), new ListT(ScalarT::string())), initialised: true),
            ...$fixed(),
            new PropertyMetadata('detail', ScalarT::string(), initialised: false),
        ], 'A rendered problem carrying the fields that failed validation, sent as problem+json.'),
        'App\\Problems\\DebugProblem' => new ClassMetadata('App\\Problems\\DebugProblem', $fixed(), 'An RFC 9457 problem that also carries whatever else Laravel rendered, its debug members when app.debug is on.'),
        // Not final: a subclass's instance is one too, so nothing pins its `type`.
        'App\\Problems\\ErrorProblem' => new ClassMetadata('App\\Problems\\ErrorProblem', [
            new PropertyMetadata('type', ScalarT::string(), initialised: true),
            new PropertyMetadata('title', ScalarT::string(), initialised: true),
            new PropertyMetadata('status', ScalarT::int(), initialised: true),
            new PropertyMetadata('detail', ScalarT::string(), initialised: false),
        ], 'The problem any error is sent as, which problems saying more about one family of error extend.'),
        'App\\Problems\\SentProblem' => new ClassMetadata('App\\Problems\\SentProblem', [
            new PropertyMetadata('type', ScalarT::string(), initialised: true),
            new PropertyMetadata('status', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
        ], 'An RFC 9457 problem that sends itself, which problems saying more about one family of error extend.'),
    ];
}

function statusTextProblemClass(): ClassMetadata
{
    return new ClassMetadata('App\\Problems\\HttpProblem', [
        new PropertyMetadata('type', new LiteralT('about:blank'), initialised: true),
        new PropertyMetadata('title', ScalarT::string(), initialised: true),
        new PropertyMetadata('status', ScalarT::int(), initialised: true),
    ], 'An RFC 9457 problem whose title is the reason phrase of the status the rendered response carries.');
}

it('illustrates each error with the reason phrase of its own status, byte-identically', function (): void {
    setBuild('documents.default.routes.include', ['api/*']);

    $callback = static fn (Response $response, Throwable $e, Request $request): Response => $response;
    $answers = [
        ModelNotFoundException::class => statusTextProblemObject(),
        HttpException::class => statusTextProblemArray(),
        ValidationException::class => statusTextFieldProblem(),
        AccessDeniedHttpException::class => statusTextDebugProblem(),
        ConflictHttpException::class => statusTextErrorProblem(),
        GoneHttpException::class => statusTextSentProblem(),
    ];
    $callables = [];
    foreach ($answers as $thrown => $answer) {
        $callables[registerRespondCallback($callback, $thrown)] = new ActionAnalysis(returns: [new ReturnSite($answer, new SourceLocation(''))]);
    }

    // A status Symfony's table has no phrase for, which only the `??` fallback can title.
    $engine = static fn (): TypeEngine => WorkbenchEngine::make($callables, statusTextProblemClasses(), [
        FormController::class.'::index' => new ActionAnalysis(throws: [
            new ThrownException(HttpException::class, 599, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            new ThrownException(ConflictHttpException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            new ThrownException(GoneHttpException::class, 410, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
        ]),
        ValidationController::class.'::store' => new ActionAnalysis(throws: [
            new ThrownException(AccessDeniedHttpException::class, 403, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
        ]),
    ]);

    $routes = static function (Router $router): void {
        $router->get('api/probe-forms/{form}', [FormController::class, 'show']);
        $router->get('api/probe-forms', [FormController::class, 'index']);
        $router->post('api/probe-tickets', [ValidationController::class, 'store']);
    };

    $warm = assertWarmEqualsCold($routes, $routes, $engine);
    $document = emittedArray($warm);

    assertGolden('workbench-respond-status-text.uir.json', (new UirEmitter)->emit($warm->document));

    $example = static fn (string $path, string $status, string $verb = 'get'): mixed => resolveResponse($document, $document['paths'][$path][$verb]['responses'][$status] ?? [])['content']['application/problem+json']['example'] ?? null;

    expect($example('/api/probe-forms/{form}', '404'))->toBe(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404])
        ->and($example('/api/probe-forms', '599'))->toBe(['type' => 'about:blank', 'title' => 'Error', 'status' => 599])
        // The parent constructor writes the title, and reading the rendered body after the problem is built
        // leaves the status it was sent with as it was: each still says what the server sends.
        ->and($example('/api/probe-tickets', '422', 'post'))->toBe(['errors' => [], 'type' => 'about:blank', 'title' => 'Unprocessable Content', 'status' => 422, 'detail' => 'string'])
        ->and($example('/api/probe-tickets', '403', 'post'))->toBe(['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403])
        // A problem built by its class's own name is exactly that class, so it holds the `type` that class's
        // constructor writes, final or not; the schema every subclass shares pins none.
        ->and($example('/api/probe-forms', '409'))->toBe(['type' => 'about:blank', 'title' => 'Conflict', 'status' => 409, 'detail' => 'string'])
        ->and($example('/api/probe-forms', '410'))->toBe(['type' => 'about:blank', 'status' => 410, 'title' => 'Gone'])
        ->and($document['components']['schemas']['ErrorProblem']['properties']['type'] ?? null)->toBe(['type' => 'string'])
        ->and($document['components']['schemas']['SentProblem']['properties']['type'] ?? null)->toBe(['type' => 'string'])
        ->and($document['components']['schemas']['HttpProblem']['properties']['title'] ?? null)->toBe(['type' => 'string']);
});

it('scripts what the real engine recovers from the fixture app’s own callbacks', function (string $method, string $thrown, Closure $scripted): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $source = (string) file_get_contents(FixtureRunner::path('app/Exceptions/RespondCallbacks.php'));
    $line = 0;
    foreach (explode("\n", $source) as $index => $text) {
        if (str_contains($text, 'public function '.$method.'(')) {
            $line = $index + 3;
        }
    }
    expect($line)->toBeGreaterThan(3);

    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/RespondCallbacks.php',
        '',
        '',
        line: $line,
        param: 'e',
        narrowType: ReceivedException::byRespondCallback($thrown) ?? '',
        every: true,
    ));
    $rewrites = array_values(array_filter(
        $analysis->returns,
        static fn (ReturnSite $site): bool => $site->type instanceof ClassT && $site->type->fqcn === 'Illuminate\\Http\\JsonResponse',
    ));

    expect(array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $rewrites))->toBe([$scripted()->toArray()]);
})->with([
    'the object body built in place' => ['problemObject', ModelNotFoundException::class, statusTextProblemObject(...)],
    'the array body a helper builds' => ['everywhere', HttpException::class, statusTextProblemArray(...)],
    'the object a factory builds through its parent constructor' => ['problemFactories', ValidationException::class, statusTextFieldProblem(...)],
    'the object a factory builds before reading the rendered body' => ['problemFactories', AccessDeniedHttpException::class, statusTextDebugProblem(...)],
    'the object a factory builds as `self`, of a class others extend' => ['problemFromSelf', ConflictHttpException::class, statusTextErrorProblem(...)],
    'the object sending itself, of a class others extend' => ['problemSendingItself', GoneHttpException::class, statusTextSentProblem(...)],
])->group('fixture');

it('scripts each problem class as the real engine reads it', function (string $class): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $facts = static fn (ClassMetadata $class): array => [$class->summary, array_map(
        static fn (PropertyMetadata $property): array => [$property->name, $property->type->toArray(), $property->initialised],
        $class->properties,
    )];

    expect($facts(statusTextProblemClasses()[$class]))->toBe($facts(ClassMetadata::fromArray(FixtureRunner::classMetadata($class))));
})->with(array_keys(statusTextProblemClasses()))->group('fixture');
