<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ProblemDetails;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;

/**
 * The RFC 9457 Problem Details preset (design §6 chain), activated by
 * `error_responses => 'problem-details'`. Maps framework exceptions to reusable
 * `application/problem+json` responses that all build on one shared `ProblemDetails` schema component and
 * hoist to shared `#/components/responses/Problem*`, so many operations reference one response. A bare
 * `HttpException` with a resolved status hint gets a per-status `Problem{status}`.
 *
 * Ordered EARLY: ahead of the framework-defaults tier and the fallback, behind only the inferred-handler
 * tier — an active preset defines the error contract, but a real app handler still wins. Self-gated, so
 * the mapper can sit permanently in the resolved chain without affecting other documents.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class ProblemDetailsExceptionToResponse implements ExceptionToResponse
{
    private const HTTP_EXCEPTION = 'Symfony\\Component\\HttpKernel\\Exception\\HttpException';

    public function supports(ThrownException $exception, RouteContext $context): bool
    {
        if ($context->document->errorResponses !== 'problem-details') {
            return false;
        }

        if ($this->match($exception->exceptionFqcn) !== null) {
            return true;
        }

        // A bare HttpException is documentable only when its status folded to a constant.
        return is_a($exception->exceptionFqcn, self::HTTP_EXCEPTION, true) && $exception->httpStatusHint !== null;
    }

    public function producer(): string
    {
        return 'integration:problem-details';
    }

    public function toResponse(
        ThrownException $exception,
        RouteContext $context,
        ComponentRegistry $components,
    ): ?ResponseDraft {
        $problemRef = $components->reference(
            ProblemDetailsSchema::SCHEMA_NAME,
            ProblemDetailsSchema::schema(),
            ProblemDetailsSchema::SCHEMA_ID,
        );

        $entry = $this->match($exception->exceptionFqcn);
        if ($entry !== null) {
            $ref = $components->referenceResponse($entry['component'], ProblemDetailsSchema::response($entry, $problemRef, $context->document->errorsShape));

            return $this->refResponse($entry['status'], $ref);
        }

        if (is_a($exception->exceptionFqcn, self::HTTP_EXCEPTION, true) && $exception->httpStatusHint !== null) {
            $status = $exception->httpStatusHint;
            $ref = $components->referenceResponse('Problem'.$status, ProblemDetailsSchema::dynamicResponse($status, $problemRef));

            return $this->refResponse((string) $status, $ref);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    private function refResponse(string $status, array $ref): ResponseDraft
    {
        $draft = new ResponseDraft($status);
        $reference = is_string($ref['$ref'] ?? null) ? $ref['$ref'] : '';
        $draft->setRef($reference, Contribution::integration('problem-details'));

        return $draft;
    }

    /**
     * @return array{component: string, status: string, title: string, description: string, validation: bool}|null
     */
    private function match(string $fqcn): ?array
    {
        foreach (ProblemDetailsSchema::table() as $base => $entry) {
            if ($fqcn === $base || is_a($fqcn, $base, true)) {
                return $entry;
            }
        }

        return null;
    }
}
