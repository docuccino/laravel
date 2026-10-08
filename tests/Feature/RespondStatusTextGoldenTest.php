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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Workbench\App\Http\Controllers\FormController;

/**
 * The document an application gets when its `$exceptions->respond()` hook answers every error with RFC
 * 9457's `about:blank` problem, titled with the reason phrase of the status it is sent with
 * (`Response::$statusTexts[$status] ?? 'Error'`): each error's example says what the server sends under
 * that status — the phrase for a status Symfony names, the `??` fallback for one it does not — while the
 * schema shared across statuses names no phrase. An object body (`new HttpProblem($response)`) and an
 * array one answer alike. Byte-locked, warm as well as cold; the scripted analyses are what the real
 * engine recovers from the fixture app's own callback, which the last test holds them to.
 */
function statusTextProblemObject(): ClassT
{
    return new ClassT('Illuminate\\Http\\JsonResponse', [
        new ClassT('App\\Problems\\HttpProblem'),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
        new ArrayShapeT([
            new ArrayShapeField('status', new StatusMarkerT),
            new ArrayShapeField('title', new StatusTextMarkerT(ScalarT::string(), new LiteralT('Error'))),
        ]),
    ]);
}

function statusTextProblemArray(): ClassT
{
    return new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([
            new ArrayShapeField('type', new LiteralT('about:blank')),
            new ArrayShapeField('title', new StatusTextMarkerT(ScalarT::string(), new LiteralT('Error'))),
            new ArrayShapeField('status', new StatusMarkerT),
            new ArrayShapeField('errors', new ListT(new UnknownT('mixed')), optional: true),
        ]),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
    ]);
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
    ];
    $callables = [];
    foreach ($answers as $thrown => $answer) {
        $callables[registerRespondCallback($callback, $thrown)] = new ActionAnalysis(returns: [new ReturnSite($answer, new SourceLocation(''))]);
    }

    // A status Symfony's table has no phrase for, which only the `??` fallback can title.
    $engine = static fn (): TypeEngine => WorkbenchEngine::make($callables, ['App\\Problems\\HttpProblem' => statusTextProblemClass()], [
        FormController::class.'::index' => new ActionAnalysis(throws: [
            new ThrownException(HttpException::class, 599, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
        ]),
    ]);

    $routes = static function (Router $router): void {
        $router->get('api/probe-forms/{form}', [FormController::class, 'show']);
        $router->get('api/probe-forms', [FormController::class, 'index']);
    };

    $warm = assertWarmEqualsCold($routes, $routes, $engine);
    $document = emittedArray($warm);

    assertGolden('workbench-respond-status-text.uir.json', (new UirEmitter)->emit($warm->document));

    $example = static fn (string $path, string $status): mixed => resolveResponse($document, $document['paths'][$path]['get']['responses'][$status] ?? [])['content']['application/problem+json']['example'] ?? null;

    expect($example('/api/probe-forms/{form}', '404'))->toBe(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404])
        ->and($example('/api/probe-forms', '599'))->toBe(['type' => 'about:blank', 'title' => 'Error', 'status' => 599])
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
])->group('fixture');

it('scripts the problem class as the real engine reads it', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $facts = static fn (ClassMetadata $class): array => [$class->summary, array_map(
        static fn (PropertyMetadata $property): array => [$property->name, $property->type->toArray(), $property->initialised],
        $class->properties,
    )];

    expect($facts(statusTextProblemClass()))->toBe($facts(ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Problems\\HttpProblem'))));
})->group('fixture');
