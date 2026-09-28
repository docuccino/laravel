<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\InferredHandler\ExceptionMapping;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerReflector;
use Docuccino\Laravel\Integrations\InferredHandler\RenderCallback;
use Docuccino\Laravel\Tests\Support\DecoratingExceptionHandler;
use Docuccino\Laravel\Tests\Support\ExceptionTranslations;
use Docuccino\Laravel\Tests\Support\InvokableRenderer;
use Docuccino\Laravel\Tests\Support\InvokableResponder;
use Docuccino\Laravel\Tests\Support\PairRenderer;
use Docuccino\Laravel\Tests\Support\UninitializedPropertyExceptionHandler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The reflector has to discover every shape of registered render callback Laravel stores, and never drop
 * one silently. Laravel wraps every non-Closure render callable via `Closure::fromCallable()`, so an
 * invokable renderer, an `[$object, 'method']` pair and a first-class callable all arrive as closures
 * naming a real method — each is reported as that method (class + method), while a genuine anonymous
 * closure keeps its by-line locator. A decorator around the handler (Collision, in console) is walked
 * through, and an unanalysable callback is recorded rather than dropped.
 */

/**
 * A free-function render callback. Laravel wraps it via `Closure::fromCallable()`, so it arrives as a
 * non-anonymous closure with no owning class — one of the reflector's three skip reasons: nothing to
 * analyse by name, and its declaration line isn't a closure literal.
 */
function reflectorFreeFunctionRenderer(NotFoundHttpException $e): JsonResponse
{
    return new JsonResponse(['error' => 'gone'], 410);
}

/** Register a callback on the app handler and return the render callback the reflector newly discovered for it. */
function reflectNewlyRegistered(callable $callback): RenderCallback
{
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable($callback);

    // Laravel appends in registration order, so the one just registered is last.
    $callbacks = (new HandlerReflector($handler))->renderCallbacks();

    return $callbacks[array_key_last($callbacks)];
}

it('discovers each Laravel render-callable form under its real analysis target', function (string $form, ?string $class, ?string $method): void {
    $callable = match ($form) {
        'anonymous' => static fn (NotFoundHttpException $e) => response()->json(['error' => 'gone'], 410),
        'invokable' => Closure::fromCallable(new InvokableRenderer),
        'pair' => Closure::fromCallable([new PairRenderer, 'handle']),
        'first-class' => (new PairRenderer)->handle(...),
    };

    $callback = reflectNewlyRegistered($callable);

    expect($callback->exceptionType)->toBe(NotFoundHttpException::class)
        ->and($callback->parameterName)->toBe('e')
        ->and($callback->at->isMethod())->toBe($method !== null)
        ->and($callback->at->class)->toBe($class)
        ->and($callback->at->method)->toBe($method);
})->with([
    'anonymous closure (by-line)' => ['anonymous', null, null],
    'invokable object (__invoke method)' => ['invokable', InvokableRenderer::class, '__invoke'],
    '[object, method] pair' => ['pair', PairRenderer::class, 'handle'],
    'first-class callable' => ['first-class', PairRenderer::class, 'handle'],
]);

it('keeps an anonymous closure on its by-line locator (the closure start line)', function (): void {
    $closure = static fn (NotFoundHttpException $e) => response()->json(['error' => 'gone'], 410);
    $expectedLine = (new ReflectionFunction($closure))->getStartLine();

    $callback = reflectNewlyRegistered($closure);

    expect($callback->at->isMethod())->toBeFalse()
        ->and($callback->at->line)->toBe($expectedLine);
});

it('walks through a handler decorator to the wrapped handler that owns the callbacks', function (): void {
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable(Closure::fromCallable(new InvokableRenderer));

    // Wrap the real handler the way Collision's console adapter does — no renderCallbacks of its own.
    $decorated = new DecoratingExceptionHandler($handler);
    $callbacks = (new HandlerReflector($decorated))->renderCallbacks();

    $invokable = array_values(array_filter(
        $callbacks,
        static fn (RenderCallback $c): bool => $c->at->class === InvokableRenderer::class,
    ));

    expect($invokable)->toHaveCount(1)
        ->and($invokable[0]->at->method)->toBe('__invoke');
});

it('walks past an UNINITIALIZED typed property to reach the wrapped handler', function (): void {
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable(Closure::fromCallable(new InvokableRenderer));

    // The decorator declares an uninitialized typed property before $inner: reading it throws, and a
    // per-walk (rather than per-property) guard would abort discovery and return zero callbacks.
    $callbacks = (new HandlerReflector(new UninitializedPropertyExceptionHandler($handler)))->renderCallbacks();

    $invokable = array_values(array_filter(
        $callbacks,
        static fn (RenderCallback $c): bool => $c->at->class === InvokableRenderer::class,
    ));

    expect($invokable)->toHaveCount(1)
        ->and($invokable[0]->at->method)->toBe('__invoke');
});

it('records every unanalysable render-callback shape as skipped rather than dropping it silently', function (callable $callback): void {
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable($callback);

    $reflector = new HandlerReflector($handler);

    expect($reflector->renderCallbacks())->toBe([])
        ->and($reflector->skipped())->toHaveCount(1)
        ->and($reflector->skipped()[0])->toContain('closure@');
})->with([
    // One row per skip reason the resolver names.
    // A first parameter with a builtin type isn't an exception the tier can bind.
    'builtin first parameter' => [fn (): Closure => static fn (string $whoops) => response()->json([], 400)],
    // No parameters means no exception to narrow the analysis to.
    'zero parameters' => [fn (): Closure => static fn () => response()->json([], 400)],
    // A bound free function is non-anonymous with no owning class: nothing to analyse by name, and its
    // declaration line isn't a closure literal, so it's skipped rather than mis-located.
    'bound free function' => [fn (): Closure => Closure::fromCallable('reflectorFreeFunctionRenderer')],
]);

it('finds the respond() callback in each form Laravel stores it, with its parameters by position', function (callable $callback, ?string $class, ?string $method, array $names): void {
    /** @var Handler $handler */
    $handler = app(ExceptionHandler::class);
    $handler->respondUsing($callback);

    $respond = (new HandlerReflector($handler))->respondCallback();

    expect($respond)->not->toBeNull()
        ->and($respond?->at->class)->toBe($class)
        ->and($respond?->at->method)->toBe($method)
        ->and([$respond?->responseParameter, $respond?->exceptionParameter, $respond?->requestParameter])->toBe($names);
})->with([
    'anonymous closure' => [static fn (Response $response, Throwable $e, Request $request): Response => $response, null, null, ['response', 'e', 'request']],
    'invokable object' => [new InvokableResponder, InvokableResponder::class, '__invoke', ['rendered', 'thrown', 'incoming']],
    '[object, method] pair' => [[new InvokableResponder, 'reshape'], InvokableResponder::class, 'reshape', ['rendered', null, null]],
]);

it('locates an anonymous respond() callback by the line it starts on, and narrows its exception parameter to what it is handed', function (): void {
    $closure = static fn (Response $response, Throwable $e): Response => $response;
    $line = (new ReflectionFunction($closure))->getStartLine();

    /** @var Handler $handler */
    $handler = app(ExceptionHandler::class);
    $handler->respondUsing($closure);

    $ref = (new HandlerReflector($handler))->respondCallback()?->ref(ModelNotFoundException::class);

    // `Handler::render()` prepares a missing model into a `NotFoundHttpException` before the callback is
    // called, so a branch on `ModelNotFoundException` there is never taken for this throw.
    expect($ref?->line)->toBe($line)
        ->and($ref?->narrowParameter)->toBe('e')
        ->and($ref?->narrowType)->toBe(NotFoundHttpException::class)
        ->and($ref?->narrowToEvery)->toBeTrue();
});

it('asks once for every thrown type where the callback never names the exception', function (): void {
    /** @var Handler $handler */
    $handler = app(ExceptionHandler::class);
    $handler->respondUsing(static fn (Response $response): Response => $response);

    $respond = (new HandlerReflector($handler))->respondCallback();

    expect($respond?->ref(ModelNotFoundException::class)->symbol())->toBe($respond?->ref(RuntimeException::class)->symbol());
});

it('reports a respond() callback it cannot locate rather than treating the handler as having none', function (): void {
    /** @var Handler $handler */
    $handler = app(ExceptionHandler::class);
    $handler->respondUsing('reflectorFreeFunctionResponder');

    $reflector = new HandlerReflector($handler);

    expect($reflector->respondCallback())->toBeNull()
        ->and($reflector->respondUnlocated())->toBe('::reflectorFreeFunctionResponder');
});

it('finds no respond() callback on a handler that registered none', function (): void {
    $reflector = new HandlerReflector(app(ExceptionHandler::class));

    expect($reflector->respondCallback())->toBeNull()
        ->and($reflector->respondUnlocated())->toBeNull();
});

/** A free function handed to `respond()` by name: a bound closure with no class to analyse it as. */
function reflectorFreeFunctionResponder(Response $response): Response
{
    return $response;
}

it('reads the exception map in each form map() accepts, in registration order', function (): void {
    /** @var Handler $handler */
    $handler = app(ExceptionHandler::class);
    $handler->map(ModelNotFoundException::class, AuthorizationException::class);
    $handler->map(NotFoundHttpException::class, static fn (NotFoundHttpException $e) => new AuthorizationException);
    $handler->map(static fn (RecordsNotFoundException $missing) => new AuthorizationException);
    $handler->map(TokenMismatchException::class, (new ExceptionTranslations)->forbid(...));
    $handler->map(BadRequestHttpException::class, Closure::fromCallable('strval'));

    $mappings = (new HandlerReflector($handler))->exceptionMappings();

    // The class-string target is read as the class, not as the closure map() wraps it in; a closure keeps
    // its line, a method its class and name, and a mapper with no source is kept, unlocated.
    expect(array_map(static fn (ExceptionMapping $m): array => [$m->from, $m->target, $m->at === null, $m->at?->class, $m->at?->method, $m->parameterName], $mappings))->toBe([
        [ModelNotFoundException::class, AuthorizationException::class, true, null, null, null],
        [NotFoundHttpException::class, null, false, null, null, 'e'],
        [RecordsNotFoundException::class, null, false, null, null, 'missing'],
        [TokenMismatchException::class, null, false, ExceptionTranslations::class, 'forbid', 'e'],
        [BadRequestHttpException::class, null, true, null, null, null],
    ])
        ->and($mappings[1]->at?->line)->toBeGreaterThan(0)
        ->and($mappings[0]->ref(ModelNotFoundException::class))->toBeNull()
        ->and($mappings[4]->ref(BadRequestHttpException::class))->toBeNull()
        ->and($mappings[1]->ref(NotFoundHttpException::class)?->returnsExceptions)->toBeTrue();
});

it('matches a throw to the first entry keyed on its class or an ancestor, as mapException() does', function (): void {
    /** @var Handler $handler */
    $handler = app(ExceptionHandler::class);
    $handler->map(RecordsNotFoundException::class, AuthorizationException::class);
    $handler->map(ModelNotFoundException::class, AuthenticationException::class);

    $reflector = new HandlerReflector($handler);

    expect($reflector->mappingFor(ModelNotFoundException::class)?->target)->toBe(AuthorizationException::class)
        ->and($reflector->mappingFor(NotFoundHttpException::class))->toBeNull();
});

it('finds no exception map on a handler that registered none', function (): void {
    expect((new HandlerReflector(app(ExceptionHandler::class)))->exceptionMappings())->toBe([]);
});
