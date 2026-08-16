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

/**
 * Every status the table names, and the component name it must publish under — written out rather than
 * derived from the phrase, since deriving it is the implementation and would agree with any mapping.
 * These are the type names a generated client is written against, so each one is pinned.
 */
const EXPECTED_COMPONENT_NAMES = [
    ['400', 'BadRequest'],
    ['401', 'Unauthorized'],
    ['403', 'Forbidden'],
    ['404', 'NotFound'],
    ['405', 'MethodNotAllowed'],
    ['409', 'Conflict'],
    ['422', 'UnprocessableEntity'],
    ['429', 'TooManyRequests'],
    ['500', 'InternalServerError'],
    ['503', 'ServiceUnavailable'],
];

it('names every mapped status after its reason phrase, as a legal component key', function (string $status, string $name): void {
    expect(FrameworkExceptionTable::componentName($status))->toBe($name)
        ->and($name)->toMatch('/^[A-Za-z0-9._-]+$/');
})->with(EXPECTED_COMPONENT_NAMES);

it('leaves no status in the table without a pinned name', function (): void {
    // The row above is a literal list, so it can only cover every entry if this says it does: a status
    // added to the table without a name here would otherwise go out named by nobody's decision.
    expect(array_column(EXPECTED_COMPONENT_NAMES, 0))
        ->toBe(array_column(FrameworkExceptionTable::reasonPhrases(), 0));
});

it('declares no name for a status with no reason phrase of its own', function (): void {
    // `Error` names nothing, and every unlisted status would claim it — so an unlisted one declares
    // nothing and keeps `Error<status>`.
    expect(FrameworkExceptionTable::componentName('418'))->toBeNull()
        ->and(FrameworkExceptionTable::componentName('402'))->toBeNull();
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
