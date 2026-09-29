<?php

declare(strict_types=1);

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Support\ReasonPhrase;
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
    // Symfony's HttpException family. Each pins its status in a vendor/ constructor an analyser
    // strips, so the row here is the only thing standing between the consumer and a false 500.
    'http-bad-request → 400' => ['Symfony\\Component\\HttpKernel\\Exception\\BadRequestHttpException', '400', false],
    'http-unauthorized → 401' => ['Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException', '401', false],
    'http-access-denied → 403' => ['Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException', '403', false],
    'http-not-found → 404' => ['Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException', '404', false],
    'http-method-not-allowed → 405' => ['Symfony\\Component\\HttpKernel\\Exception\\MethodNotAllowedHttpException', '405', false],
    'http-not-acceptable → 406' => ['Symfony\\Component\\HttpKernel\\Exception\\NotAcceptableHttpException', '406', false],
    'http-conflict → 409' => ['Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException', '409', false],
    'http-gone → 410' => ['Symfony\\Component\\HttpKernel\\Exception\\GoneHttpException', '410', false],
    'http-length-required → 411' => ['Symfony\\Component\\HttpKernel\\Exception\\LengthRequiredHttpException', '411', false],
    'http-precondition-failed → 412' => ['Symfony\\Component\\HttpKernel\\Exception\\PreconditionFailedHttpException', '412', false],
    'http-unsupported-media-type → 415' => ['Symfony\\Component\\HttpKernel\\Exception\\UnsupportedMediaTypeHttpException', '415', false],
    // 422 WITHOUT the `errors` map: that shape comes from a validator, and this exception carries none.
    'http-unprocessable-entity → 422' => ['Symfony\\Component\\HttpKernel\\Exception\\UnprocessableEntityHttpException', '422', false],
    'http-locked → 423' => ['Symfony\\Component\\HttpKernel\\Exception\\LockedHttpException', '423', false],
    'http-precondition-required → 428' => ['Symfony\\Component\\HttpKernel\\Exception\\PreconditionRequiredHttpException', '428', false],
    'http-too-many-requests → 429' => ['Symfony\\Component\\HttpKernel\\Exception\\TooManyRequestsHttpException', '429', false],
    'http-service-unavailable → 503' => ['Symfony\\Component\\HttpKernel\\Exception\\ServiceUnavailableHttpException', '503', false],
    // Laravel's own HttpException subclasses, which pin theirs the same way.
    'malformed-url → 400' => ['Illuminate\\Http\\Exceptions\\MalformedUrlException', '400', false],
    'invalid-signature → 403' => ['Illuminate\\Routing\\Exceptions\\InvalidSignatureException', '403', false],
    'post-too-large → 413' => ['Illuminate\\Http\\Exceptions\\PostTooLargeException', '413', false],
    // Subtype: a subclass of a mapped base inherits its mapping.
    'a ModelNotFound subclass inherits 404' => [FixtureMissingModelException::class, '404', false],
    // …which is why the table lists what pins a status and no more. Laravel's throttle exception pins
    // nothing of its own and inherits 429 here exactly as it inherits it at runtime; a row would be noise.
    'throttle inherits its base 429' => ['Illuminate\\Http\\Exceptions\\ThrottleRequestsException', '429', false],
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
    'http-bad-request → 400' => ['Symfony\\Component\\HttpKernel\\Exception\\BadRequestHttpException', '400'],
    'http-unauthorized → 401' => ['Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException', '401'],
    'http-access-denied → 403' => ['Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException', '403'],
    'http-not-found → 404' => ['Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException', '404'],
    'http-method-not-allowed → 405' => ['Symfony\\Component\\HttpKernel\\Exception\\MethodNotAllowedHttpException', '405'],
    'http-not-acceptable → 406' => ['Symfony\\Component\\HttpKernel\\Exception\\NotAcceptableHttpException', '406'],
    'http-conflict → 409' => ['Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException', '409'],
    'http-gone → 410' => ['Symfony\\Component\\HttpKernel\\Exception\\GoneHttpException', '410'],
    'http-length-required → 411' => ['Symfony\\Component\\HttpKernel\\Exception\\LengthRequiredHttpException', '411'],
    'http-precondition-failed → 412' => ['Symfony\\Component\\HttpKernel\\Exception\\PreconditionFailedHttpException', '412'],
    'http-unsupported-media-type → 415' => ['Symfony\\Component\\HttpKernel\\Exception\\UnsupportedMediaTypeHttpException', '415'],
    'http-unprocessable-entity → 422' => ['Symfony\\Component\\HttpKernel\\Exception\\UnprocessableEntityHttpException', '422'],
    'http-locked → 423' => ['Symfony\\Component\\HttpKernel\\Exception\\LockedHttpException', '423'],
    'http-precondition-required → 428' => ['Symfony\\Component\\HttpKernel\\Exception\\PreconditionRequiredHttpException', '428'],
    'http-too-many-requests → 429' => ['Symfony\\Component\\HttpKernel\\Exception\\TooManyRequestsHttpException', '429'],
    'http-service-unavailable → 503' => ['Symfony\\Component\\HttpKernel\\Exception\\ServiceUnavailableHttpException', '503'],
    'malformed-url → 400' => ['Illuminate\\Http\\Exceptions\\MalformedUrlException', '400'],
    'invalid-signature → 403' => ['Illuminate\\Routing\\Exceptions\\InvalidSignatureException', '403'],
    'post-too-large → 413' => ['Illuminate\\Http\\Exceptions\\PostTooLargeException', '413'],
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
    $classified = [
        'Illuminate\\Validation\\ValidationException',
        'Illuminate\\Auth\\AuthenticationException',
        'Illuminate\\Auth\\Access\\AuthorizationException',
        'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
        'Illuminate\\Database\\RecordsNotFoundException',
        'Symfony\\Component\\HttpKernel\\Exception\\BadRequestHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\MethodNotAllowedHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\NotAcceptableHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\GoneHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\LengthRequiredHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\PreconditionFailedHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\UnsupportedMediaTypeHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\UnprocessableEntityHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\LockedHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\PreconditionRequiredHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\TooManyRequestsHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\ServiceUnavailableHttpException',
        'Illuminate\\Http\\Exceptions\\MalformedUrlException',
        'Illuminate\\Routing\\Exceptions\\InvalidSignatureException',
        'Illuminate\\Http\\Exceptions\\PostTooLargeException',
    ];

    expect($classified)->toBe(FrameworkExceptionTable::exceptions())
        ->and($classified)->not->toBeEmpty();
});

it('describes an error by the reason phrase every other response under its status carries', function (int $code, string $phrase): void {
    // An error's status is whatever the code threw, not only the ones Laravel ships an exception for, so
    // its description comes off the one registry the document describes a success or a declared response
    // by. Every registered code, so a status outside the framework's own exceptions (a `501`, a `502`) is
    // held to it as firmly as a `404`.
    expect(FrameworkExceptionTable::reason((string) $code))->toBe($phrase);
})->with(function (): array {
    $rows = [];
    foreach (ReasonPhrase::registered() as $code => $phrase) {
        $rows[$code.' '.$phrase] = [$code, $phrase];
    }

    return $rows;
});

it('takes each phrase from the RFC that defines the status, not from RFC 9110 alone', function (): void {
    // Three the table would get wrong by reaching for one document. 413 is where RFC 9110 §15.5.14
    // RENAMED what RFC 7231 called "Payload Too Large", and the name is a type in somebody's generated
    // client, so the current one is the one to publish. 423 and 428 RFC 9110 never registered at all;
    // their own RFCs name them. 422 keeps its RFC 4918 name, the one every error response has carried.
    expect(FrameworkExceptionTable::reason('413'))->toBe('Content Too Large')
        ->and(FrameworkExceptionTable::reason('422'))->toBe('Unprocessable Entity')
        ->and(FrameworkExceptionTable::reason('423'))->toBe('Locked')
        ->and(FrameworkExceptionTable::reason('428'))->toBe('Precondition Required')
        ->and(FrameworkExceptionTable::reason('429'))->toBe('Too Many Requests');
});

it('describes a status the registry leaves unnamed by its class, never as a bare Error', function (string $status, string $class): void {
    // RFC 9110 §15: a client that does not recognise a code treats it as the x00 of its class, so the
    // class is what is known about it. `Error` says less than that and is true of every 4xx and 5xx.
    expect(FrameworkExceptionTable::reason($status))->toBe($class);
})->with([
    'IANA leaves 418 unused' => ['418', 'Client Error'],
    'Laravel\'s CSRF 419 is unregistered' => ['419', 'Client Error'],
    'an unregistered 4xx' => ['499', 'Client Error'],
    'an unregistered 5xx' => ['599', 'Server Error'],
]);

/**
 * Every error status the registry names, and the component name it must publish under — written out
 * rather than derived from the phrase, since deriving it is the implementation and would agree with any
 * mapping. These are the type names a generated client is written against, so each one is pinned.
 */
const EXPECTED_COMPONENT_NAMES = [
    ['400', 'BadRequest'],
    ['401', 'Unauthorized'],
    ['402', 'PaymentRequired'],
    ['403', 'Forbidden'],
    ['404', 'NotFound'],
    ['405', 'MethodNotAllowed'],
    ['406', 'NotAcceptable'],
    ['407', 'ProxyAuthenticationRequired'],
    ['408', 'RequestTimeout'],
    ['409', 'Conflict'],
    ['410', 'Gone'],
    ['411', 'LengthRequired'],
    ['412', 'PreconditionFailed'],
    ['413', 'ContentTooLarge'],
    ['414', 'URITooLong'],
    ['415', 'UnsupportedMediaType'],
    ['416', 'RangeNotSatisfiable'],
    ['417', 'ExpectationFailed'],
    ['421', 'MisdirectedRequest'],
    ['422', 'UnprocessableEntity'],
    ['423', 'Locked'],
    ['424', 'FailedDependency'],
    ['425', 'TooEarly'],
    ['426', 'UpgradeRequired'],
    ['428', 'PreconditionRequired'],
    ['429', 'TooManyRequests'],
    ['431', 'RequestHeaderFieldsTooLarge'],
    ['451', 'UnavailableForLegalReasons'],
    ['500', 'InternalServerError'],
    ['501', 'NotImplemented'],
    ['502', 'BadGateway'],
    ['503', 'ServiceUnavailable'],
    ['504', 'GatewayTimeout'],
    ['505', 'HTTPVersionNotSupported'],
    ['506', 'VariantAlsoNegotiates'],
    ['507', 'InsufficientStorage'],
    ['508', 'LoopDetected'],
    ['510', 'NotExtended'],
    ['511', 'NetworkAuthenticationRequired'],
];

it('names every registered error status after its reason phrase, as an identifier', function (string $status, string $name): void {
    // An identifier and not merely a legal component key: the name becomes a type in a generated client,
    // so it carries none of the `.` and `-` a key could.
    expect(FrameworkExceptionTable::componentName($status))->toBe($name)
        ->and($name)->toMatch('/^[A-Z][A-Za-z0-9]*$/');
})->with(EXPECTED_COMPONENT_NAMES);

it('leaves no registered error status without a pinned name', function (): void {
    // The row above is a literal list, so it can only cover every error status the registry names if this
    // says it does: a code added to the registry would otherwise go out named by nobody's decision.
    $errors = array_values(array_filter(
        array_map(strval(...), array_keys(ReasonPhrase::registered())),
        static fn (string $status): bool => (int) $status >= 400,
    ));

    expect(array_column(EXPECTED_COMPONENT_NAMES, 0))->toBe($errors)
        ->and(count($errors))->toBeGreaterThan(30);
});

it('asks for a different name for every status, so no two errors contest one by their status alone', function (): void {
    // A status default is a claim, and two DIFFERENT statuses claiming one name would retire it for both
    // into content hashes. Registered phrases are distinct, and the names made of them must stay so.
    $names = array_column(EXPECTED_COMPONENT_NAMES, 1);

    expect(array_unique($names))->toBe($names);
});

it('declares no name for a status the registry does not name', function (string $status): void {
    // Its class is not its own: every unregistered 4xx would ask for `ClientError`, so a 419 and a 499 on
    // one document would contest it and lose it together. It declares nothing and keeps `Error<status>`.
    expect(FrameworkExceptionTable::componentName($status))->toBeNull();
})->with(['418', '419', '499', '599', '4XX', 'default']);

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

/**
 * The two answers that must agree, taken off ONE call — where the response is keyed, and whether that
 * key is a reading or a stand-in.
 *
 * The rule is written out here rather than read back off the table, because a guard that asks the code
 * for its own rule agrees with whatever the code does. Stated from the contract: a status the calling
 * tier READ is published as read, whatever number it is; a status nothing read is the class's own
 * framework status where the table has one, and only where it has none is the answer a stand-in. So a
 * 500 is three different facts and exactly one of them is a placeholder.
 */
it('says where an error is keyed and whether that key was read, in one answer', function (?string $read, string $fqcn, string $status, bool $unplaced): void {
    expect(FrameworkExceptionTable::place($read, $fqcn))->toBe(['status' => $status, 'unplaced' => $unplaced]);
})->with([
    // READ. The number came from the code, so nothing about the class can overrule it — including the
    // one case a table row would have answered differently.
    'a status the code states' => ['423', 'App\\Exceptions\\LedgerRejected', '423', false],
    'a 500 the code states' => ['500', 'App\\Exceptions\\LedgerRejected', '500', false],
    'an exception that is no HTTP error, which the framework answers 500 for' => ['500', 'RuntimeException', '500', false],
    'a reading over a class the table knows' => ['404', 'Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException', '404', false],

    // NOTHING READ, but the table knows the class: its own constructor pins the number, so the document
    // states a fact rather than standing in for one.
    'a framework class the table knows' => [null, 'Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException', '409', false],
    'a subclass of one it knows' => [null, 'Illuminate\\Http\\Exceptions\\ThrottleRequestsException', '429', false],

    // NOTHING READ and no row either: the only way the unplaced status is ever the answer.
    'an application HttpException subclass' => [null, 'App\\Exceptions\\LedgerRejected', '500', true],
    'the framework HttpException base, which takes any status' => [null, 'Symfony\\Component\\HttpKernel\\Exception\\HttpException', '500', true],
]);

/**
 * The guard executed rather than asserted: the shape it should refuse, refused. `classification()` is
 * the older spelling of the same rule and every tier that publishes an unread status keys through it,
 * so the two answering differently would be the defect this file exists to prevent — one tier standing
 * in where another read.
 */
it('answers the older spelling of the same question identically', function (): void {
    $classes = [...FrameworkExceptionTable::exceptions(), 'RuntimeException', 'App\\Exceptions\\LedgerRejected'];

    foreach ($classes as $fqcn) {
        expect(FrameworkExceptionTable::place(null, $fqcn)['status'])->toBe(FrameworkExceptionTable::classification($fqcn));
    }

    // …and the loop is worth something: the corpus really holds both answers, so an agreement over one
    // of them is not what just passed.
    $unplaced = array_map(static fn (string $f): bool => FrameworkExceptionTable::place(null, $f)['unplaced'], $classes);

    expect($unplaced)->toContain(true)
        ->and($unplaced)->toContain(false);
});
