<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\ProbeProblemRenderer;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * The document an application gets when its own handler names the media type of an error whose body the
 * build cannot read — a status, a reason phrase, and that media type under a schema constraining nothing.
 * Byte-locked, because every other assertion about this population reaches for one key at a time and the
 * question a golden answers is what the whole response looks like: that the empty schema really is an
 * empty OBJECT in the bytes and not an empty array, that no shared error component was minted for a shape
 * nobody read, and that the framework's `{message}` is nowhere in it.
 *
 * One route, because that is all the population needs, and the renderer is a real class registered on the
 * booted handler ({@see ProbeProblemRenderer}) with the analysis it folds to scripted beside it.
 */
it('emits an error stating only the media type its handler proved, byte-identically', function (): void {
    $renderer = new ProbeProblemRenderer;

    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable($renderer);

    $function = new ReflectionFunction(Closure::fromCallable($renderer));
    $symbol = (new CallableRef(
        (string) $function->getFileName(),
        $renderer::class,
        $function->getName(),
        0,
        $function->getParameters()[0]->getName(),
        ModelNotFoundException::class,
    ))->symbol();

    $engine = static fn (): TypeEngine => WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new UnknownT('payload not folded'),
                new LiteralT(404),
                new LiteralT('application/problem+json'),
            ]),
            new SourceLocation(''),
        )]),
    ]);

    $routes = static fn (Router $router) => $router->get('api/probe-forms/{form}', [FormController::class, 'show']);

    // Warm as well as cold, because the half that did NOT fold travels as a route note: a warm hit that
    // replayed the bytes and lost the diagnostic would be a silent degradation, and this population is the
    // one where the tier answers and notes a deferral in the same breath.
    $warm = assertWarmEqualsCold($routes, $routes, $engine);

    assertGolden('workbench-handler-media-type.uir.json', (new UirEmitter)->emit($warm->document));

    expect(diagnosticsCoded($warm->diagnostics, 'inferred-handler.too-dynamic'))->toHaveCount(1);
});
