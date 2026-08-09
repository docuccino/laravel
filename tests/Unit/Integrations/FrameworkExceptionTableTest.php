<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\FrameworkErrors\FrameworkErrorsExceptionToResponse;
use Docuccino\Laravel\Integrations\ProblemDetails\ProblemDetailsSchema;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The shared framework-exception table (D6): the single source of status + reason phrase both error
 * presentations read, so they can never drift — and, in particular, agree on the RFC 9110 401 reason
 * phrase "Unauthorized" (the historical framework-errors "Unauthenticated" is gone).
 */
it('resolves each mapped exception to its status subtype-aware', function (string $fqcn, string $status, bool $validation): void {
    $facts = FrameworkExceptionTable::match($fqcn);

    expect($facts)->not->toBeNull()
        ->and($facts['status'])->toBe($status)
        ->and($facts['validation'])->toBe($validation);
})->with([
    'validation → 422' => ['Illuminate\\Validation\\ValidationException', '422', true],
    'authentication → 401' => ['Illuminate\\Auth\\AuthenticationException', '401', false],
    'authorization → 403' => ['Illuminate\\Auth\\Access\\AuthorizationException', '403', false],
    'model-not-found → 404' => ['Illuminate\\Database\\Eloquent\\ModelNotFoundException', '404', false],
    'records-not-found (parent) → 404' => ['Illuminate\\Database\\RecordsNotFoundException', '404', false],
    'http-not-found → 404' => ['Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException', '404', false],
    // Subtype: a subclass of a mapped base inherits its mapping.
    'a ModelNotFound subclass inherits 404' => [FixtureMissingModelException::class, '404', false],
]);

it('declines an unmapped exception', function (): void {
    expect(FrameworkExceptionTable::match('RuntimeException'))->toBeNull();
});

it('uses the RFC reason phrase for every mapped status', function (string $status, string $reason): void {
    expect(FrameworkExceptionTable::reason($status))->toBe($reason);
})->with(FrameworkExceptionTable::reasonPhrases());

it('locks 401 to Unauthorized and degrades an unlisted status to Error', function (): void {
    expect(FrameworkExceptionTable::reason('401'))->toBe('Unauthorized')
        ->and(FrameworkExceptionTable::reason('500'))->toBe('Internal Server Error')
        ->and(FrameworkExceptionTable::reason('418'))->toBe('Error')
        ->and(FrameworkExceptionTable::reason('402'))->toBe('Error');
});

it('makes the framework-errors description and problem-details title agree on the 401 phrase', function (): void {
    $auth = 'Illuminate\\Auth\\AuthenticationException';

    $frameworkDescription = FrameworkErrorsExceptionToResponse::table()[$auth]['description'];
    $problemTitle = ProblemDetailsSchema::table()[$auth]['title'];

    expect($frameworkDescription)->toBe('Unauthorized')
        ->and($problemTitle)->toBe('Unauthorized')
        ->and($frameworkDescription)->toBe($problemTitle);
});

/** A subclass of ModelNotFoundException, to prove subtype-aware matching. */
class FixtureMissingModelException extends ModelNotFoundException {}
