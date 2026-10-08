<?php

declare(strict_types=1);

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\InferredHandler\ExceptionRenderers;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerReflector;
use Docuccino\Laravel\Integrations\InferredHandler\RenderedResponse;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\CustomJsonResponse;
use Docuccino\Laravel\Tests\Fixtures\InferredHandler\SelfRenderingMissingModel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\JsonResponse as SymfonyJsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Which class the response `respond()` is handed is, for one throw — read the way `Handler::render()`
 * builds it, and only where the build knows. Everything else is null, which leaves a guard on the class
 * reachable both ways.
 */
function renderedResponseOf(string $thrown, string $producer, ?string $mediaType = 'application/json'): ?RenderedResponse
{
    $draft = new ResponseDraft('404');
    $draft->setDescription('Not Found', Contribution::forProducer($producer));
    if ($mediaType !== null) {
        $draft->content($mediaType)->set('type', 'object', Contribution::forProducer($producer));
    }

    return RenderedResponse::of(
        new ThrownException($thrown, null, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
        $draft,
        new ExceptionRenderers(new HandlerReflector(app(ExceptionHandler::class))),
    );
}

it('knows the exact JsonResponse the framework renders an error it sends as JSON with', function (): void {
    $sent = renderedResponseOf(NotFoundHttpException::class, 'integration:framework-errors');

    expect($sent?->exact)->toBeTrue()
        // A producer the build does not know of documents the same framework rendering.
        ->and(renderedResponseOf(NotFoundHttpException::class, 'integration:acme')?->exact)->toBeTrue();
});

it('does not know the class where the framework does not build the response from the error', function (string $thrown, string $producer, ?string $mediaType): void {
    expect(renderedResponseOf($thrown, $producer, $mediaType))->toBeNull();
})->with([
    // `Handler::render()` sends the response the exception carries, whatever it is.
    'an HttpResponseException' => [HttpResponseException::class, 'fallback', 'application/json'],
    // The document does not say the error is sent as JSON, so the JSON paths are not the ones taken.
    'an error with no body published' => [NotFoundHttpException::class, 'fallback', null],
    'an error published as another media type' => [NotFoundHttpException::class, 'fallback', 'text/html'],
    // The exception renders itself and the inferred tier did not read what to.
    'an exception rendering itself, answered by another tier' => [SelfRenderingMissingModel::class, 'fallback', 'application/json'],
]);

it('knows a JsonResponse, and no more, where the inferred tier read what the application renders', function (): void {
    $sent = renderedResponseOf(SelfRenderingMissingModel::class, 'integration:inferred-handler');

    expect($sent?->exact)->toBeFalse();
});

it('answers an instanceof test only where the class settles it', function (string $class, ?bool $exact, ?bool $loose): void {
    $exactly = renderedResponseOf(NotFoundHttpException::class, 'fallback');
    $atLeast = renderedResponseOf(SelfRenderingMissingModel::class, 'integration:inferred-handler');

    expect($exactly?->isA($class))->toBe($exact)
        ->and($atLeast?->isA($class))->toBe($loose);
})->with([
    'itself' => [JsonResponse::class, true, true],
    'a parent' => [SymfonyJsonResponse::class, true, true],
    'the base response' => [Response::class, true, true],
    // Exactly a JsonResponse is not one of its subclasses; at least one may be.
    'a subclass' => [CustomJsonResponse::class, false, null],
    'a sibling' => [RedirectResponse::class, false, null],
    'the plain Illuminate response' => [IlluminateResponse::class, false, null],
    'an interface it does not implement' => [JsonSerializable::class, false, null],
    'a class nothing declares' => ['App\\Http\\Responses\\Missing', null, null],
]);
