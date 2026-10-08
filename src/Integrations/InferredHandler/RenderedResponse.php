<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Laravel\Support\FrameworkClasses;

/**
 * The class of the response `respond()` is handed for one throw, where the build knows it — a `JsonResponse`,
 * exactly or at least: what a guard on that class is answered from ({@see RespondConditions}). Known in two
 * places only. Where the application renders the exception, the inferred-handler tier's answer — read off a
 * returned `JsonResponse`, of which a subclass may still be sent. Where the framework does and the document
 * says the error is sent as JSON, the `JsonResponse` its JSON paths build exactly — every one but
 * `HttpResponseException`, which hands back whatever response it carries (and a `ValidationException` built
 * with a response of its own, which the document already describes as the framework's body).
 */
final readonly class RenderedResponse
{
    private const HTTP_RESPONSE_EXCEPTION = 'Illuminate\\Http\\Exceptions\\HttpResponseException';

    /** @param  bool  $exact  exactly a `JsonResponse`, or one of it or its subclasses */
    private function __construct(public bool $exact) {}

    public static function of(ThrownException $exception, ResponseDraft $rendered, ExceptionRenderers $renderers): ?self
    {
        if ($renderers->of($exception->exceptionFqcn) !== []) {
            return $rendered->producerFor('description') === InferredHandlerExceptionToResponse::PRODUCER ? new self(exact: false) : null;
        }

        if (is_a($exception->exceptionFqcn, self::HTTP_RESPONSE_EXCEPTION, true)) {
            return null;
        }

        return $rendered->primaryMediaType() === 'application/json' ? new self(exact: true) : null;
    }

    /** Whether the response is an instance of `$class`, or null where that is not knowable. */
    public function isA(string $class): ?bool
    {
        if (is_a(FrameworkClasses::JSON_RESPONSE, $class, true)) {
            return true;
        }

        return $this->exact && (class_exists($class) || interface_exists($class)) ? false : null;
    }
}
