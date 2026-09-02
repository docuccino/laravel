<?php

declare(strict_types=1);

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Laravel\Integrations\FrameworkErrors\FrameworkErrorsExceptionToResponse;
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

/**
 * The status an error whose own status nothing could read is published under. Written out here rather than
 * read back off the table, because a guard that asks the code for its own rule agrees with whatever the
 * code does — and this key is contested: the tier that folded a body but no status, the framework-defaults
 * tier and the terminal fallback all have to name the same one, or one error is published twice.
 */
it('classifies an unread status the same way every tier that publishes it must', function (string $fqcn, string $status): void {
    expect(FrameworkExceptionTable::classification($fqcn))->toBe($status);
})->with([
    'validation → 422' => ['Illuminate\\Validation\\ValidationException', '422'],
    'authentication → 401' => ['Illuminate\\Auth\\AuthenticationException', '401'],
    'authorization → 403' => ['Illuminate\\Auth\\Access\\AuthorizationException', '403'],
    'model-not-found → 404' => ['Illuminate\\Database\\Eloquent\\ModelNotFoundException', '404'],
    'records-not-found (parent) → 404' => ['Illuminate\\Database\\RecordsNotFoundException', '404'],
    'http-not-found → 404' => ['Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException', '404'],
    'a subclass inherits its base' => [FixtureMissingModelException::class, '404'],
    // Outside the table there is no classification at all, only the key the document cannot do without.
    'an application exception → the unplaced status' => ['App\\Exceptions\\ProbeFailure', '500'],
    'a bare RuntimeException → the unplaced status' => ['RuntimeException', '500'],
]);

it('never classifies an error at a status HTTP forbids a body on', function (): void {
    // What the inferred-handler builder rests on. It files a response whose status nothing read under a
    // classification, then asks ONE guard whether it has anything worth publishing — and that guard reads
    // a bodyless status as "no content is the truth here" rather than as a loss. A classification landing
    // on one would therefore turn "nothing was recovered" into an answer claiming the error sends no body.
    //
    // The bodyless statuses are written out rather than read off the draft, because a guard that asks the
    // code for its own rule agrees with whatever the code does.
    $bodyless = ['204', '205', '304'];
    $classified = array_map(
        FrameworkExceptionTable::classification(...),
        [...FrameworkExceptionTable::exceptions(), 'RuntimeException'],
    );

    expect(array_intersect($classified, $bodyless))->toBe([])
        ->and($classified)->not->toBeEmpty()
        // Anti-vacuity: the draft really does refuse a body at each of those, so the emptiness above is
        // the question it looks like rather than two unrelated lists failing to meet.
        ->and(array_map(static fn (string $s): bool => (new ResponseDraft($s))->isBodyless(), $bodyless))
        ->toBe([true, true, true])
        ->and(array_map(static fn (string $s): bool => (new ResponseDraft($s))->isBodyless(), $classified))
        ->not->toContain(true);
});

it('covers every mapped exception in the classification rows above', function (): void {
    // The rows are a literal list, so an exception added to the table without one would classify by
    // nobody's decision and this file would stay green.
    $classified = ['Illuminate\\Validation\\ValidationException', 'Illuminate\\Auth\\AuthenticationException', 'Illuminate\\Auth\\Access\\AuthorizationException', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException', 'Illuminate\\Database\\RecordsNotFoundException', 'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException'];

    expect($classified)->toBe(FrameworkExceptionTable::exceptions())
        ->and($classified)->not->toBeEmpty();
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

it('publishes the RFC 9110 401 phrase in both places a consumer meets it', function (): void {
    // Two published facts come off one phrase: the sentence a reader sees on the response, and the name a
    // client catches. The phrase is stated here rather than read back off the table, because a guard that
    // asks the code for its own answer agrees with whatever the code says — and 401 is the one HTTP calls
    // "Unauthorized" (RFC 9110 §15.5.2) where Laravel's own message says "Unauthenticated".
    $auth = 'Illuminate\\Auth\\AuthenticationException';

    expect(FrameworkErrorsExceptionToResponse::table()[$auth]['description'])->toBe('Unauthorized')
        ->and(FrameworkExceptionTable::componentName('401'))->toBe('Unauthorized');
});

/** A subclass of ModelNotFoundException, to prove subtype-aware matching. */
class FixtureMissingModelException extends ModelNotFoundException {}
