<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Support\ReasonPhrase;

/**
 * How Laravel's stock exceptions map to HTTP responses. Every error tier reads this one table — the
 * plain-JSON framework-errors tier, the terminal fallback and the inferred-handler builder — so no two
 * presentations can drift on a status or its label.
 *
 * What a status is CALLED is not this table's to say: it is the IANA registry's, read through
 * {@see ReasonPhrase}, which every other response in the document is described by too. An error's status
 * is whatever the code threw — a `501` from an `HttpException` subclass is as much the framework's
 * rendering as a `404` from `NotFoundHttpException` — so a list of the statuses Laravel ships an
 * exception for is the wrong domain to name them from.
 *
 * @phpstan-type PlacedStatus array{status: string, unplaced: bool}
 */
final class FrameworkExceptionTable
{
    /**
     * The status an error carrying no readable status of its own is published under. It is not a claim
     * about the exception — nothing read one — but a key the document cannot do without, since a response
     * is addressed by status and there is no other key to give it. Shared because more than one tier
     * reaches for it (the terminal fallback, and the inferred-handler builder for a render path whose
     * status did not fold), and two tiers keying one error differently would publish two responses where
     * the server sends one.
     */
    public const UNPLACED_STATUS = '500';

    /**
     * Base exception FQCN → its HTTP status and whether it carries a field-keyed `errors` map (the
     * validation shape). Matched subtype-aware, so a subclass inherits its base's mapping.
     *
     * The `HttpException` families below are here because the status is written in a `vendor/`
     * constructor, whose body an analyser strips: nothing can read the number, so a table is the only
     * way the document says what the class fixes by construction rather than falling to
     * {@see UNPLACED_STATUS} and stating a 500 the server never sends. Every entry is a class that pins
     * a literal status in its OWN constructor, and no more than that: a subclass pinning nothing of its
     * own inherits its base's mapping here exactly as it inherits the status at runtime, which is why
     * `ThrottleRequestsException` (a `TooManyRequestsHttpException`) needs no row. Symfony's family is
     * flat — sixteen direct children of `HttpException`, none an ancestor of another — so none of them
     * covers any other and all sixteen are named. The base `HttpException` itself is deliberately absent:
     * it takes an arbitrary status, so any row for it would be a guess rather than a reading.
     *
     * None carries the validation shape: an `errors` map comes from a validator, and these render as
     * Laravel's plain `{message}`. Held to the installed package by the guard in
     * `FrameworkHttpExceptionPinsTest`, which parses each constructor and fails when a row goes missing
     * or disagrees with what the class actually pins.
     *
     * @var array<string, array{status: string, validation: bool}>
     */
    private const EXCEPTIONS = [
        'Illuminate\\Validation\\ValidationException' => ['status' => '422', 'validation' => true],
        'Illuminate\\Auth\\AuthenticationException' => ['status' => '401', 'validation' => false],
        'Illuminate\\Auth\\Access\\AuthorizationException' => ['status' => '403', 'validation' => false],
        'Illuminate\\Database\\Eloquent\\ModelNotFoundException' => ['status' => '404', 'validation' => false],
        // ModelNotFoundException's PARENT: a bare `sole()`/`firstOrFail()` on the query builder throws
        // this directly, and subtype matching on the child alone would miss it.
        'Illuminate\\Database\\RecordsNotFoundException' => ['status' => '404', 'validation' => false],

        // Symfony's HttpException family, in status order.
        'Symfony\\Component\\HttpKernel\\Exception\\BadRequestHttpException' => ['status' => '400', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException' => ['status' => '401', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException' => ['status' => '403', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException' => ['status' => '404', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\MethodNotAllowedHttpException' => ['status' => '405', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\NotAcceptableHttpException' => ['status' => '406', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException' => ['status' => '409', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\GoneHttpException' => ['status' => '410', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\LengthRequiredHttpException' => ['status' => '411', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\PreconditionFailedHttpException' => ['status' => '412', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\UnsupportedMediaTypeHttpException' => ['status' => '415', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\UnprocessableEntityHttpException' => ['status' => '422', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\LockedHttpException' => ['status' => '423', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\PreconditionRequiredHttpException' => ['status' => '428', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\TooManyRequestsHttpException' => ['status' => '429', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\ServiceUnavailableHttpException' => ['status' => '503', 'validation' => false],

        // Laravel's own HttpException subclasses, likewise in status order.
        'Illuminate\\Http\\Exceptions\\MalformedUrlException' => ['status' => '400', 'validation' => false],
        'Illuminate\\Routing\\Exceptions\\InvalidSignatureException' => ['status' => '403', 'validation' => false],
        'Illuminate\\Http\\Exceptions\\PostTooLargeException' => ['status' => '413', 'validation' => false],
    ];

    /**
     * The mapped exception FQCNs in table order — drives the dataset test over every entry.
     *
     * @return list<string>
     */
    public static function exceptions(): array
    {
        return array_keys(self::EXCEPTIONS);
    }

    /**
     * The facts for an exception FQCN, subtype-aware, or null when it's outside the table.
     *
     * @return array{status: string, validation: bool}|null
     */
    public static function match(string $fqcn): ?array
    {
        foreach (self::EXCEPTIONS as $base => $facts) {
            if ($fqcn === $base || is_a($fqcn, $base, true)) {
                return $facts;
            }
        }

        return null;
    }

    /**
     * The status an error is published under when nothing in the code stated one: the exception's own
     * framework classification where this table knows it, and {@see UNPLACED_STATUS} otherwise. It is a
     * classification and not a reading — the build never saw a number — which is why the two tiers with
     * no reading to fall back on (the inferred-handler builder and the terminal fallback) both ask here
     * rather than picking their own: two tiers keying one error differently publish two responses where
     * the server sends one. The framework-defaults tier keys off {@see match()} instead, one call lower
     * on the same table, because it needs the body shape beside the status.
     */
    public static function classification(string $fqcn): string
    {
        return self::place(null, $fqcn)['status'];
    }

    /**
     * Where an error response is keyed, and whether that key is a reading or a stand-in — ONE
     * expression, because the two answers have to agree. The status a response is PUBLISHED under and
     * the build's account of WHY were computed from different facts, and a document could then carry a
     * placeholder nothing could explain; keyed off one call they cannot come apart.
     *
     * `$read` is whatever the calling tier managed to read — the throw's own status hint, the number a
     * render path folded — and null where nothing did. A stand-in is exactly "nothing read one and this
     * table knows no row either", which is the only way {@see UNPLACED_STATUS} is ever the answer: a
     * class the table DOES know is filed at the status its own constructor pins, and an exception that
     * is no `HttpException` at all reaches a tier already carrying the 500 the framework really sends.
     *
     * @return PlacedStatus
     */
    public static function place(?string $read, string $fqcn): array
    {
        if ($read !== null) {
            return ['status' => $read, 'unplaced' => false];
        }

        $facts = self::match($fqcn);

        return $facts === null
            ? ['status' => self::UNPLACED_STATUS, 'unplaced' => true]
            : ['status' => $facts['status'], 'unplaced' => false];
    }

    /**
     * The reason phrase for a status: the registered one, or the name of its class (`Server Error`) for a
     * code the registry leaves unnamed — never another code's phrase.
     */
    public static function reason(string $status): string
    {
        return ReasonPhrase::of($status);
    }

    /**
     * The component name an error body for this status is published under — the registered reason phrase
     * as one identifier, so what a client catches is called `NotFound` rather than `Error404`. Registered
     * phrases are distinct, so no two statuses ask for one name. Null for a code the registry does not
     * name: its class is shared by every unregistered code in it, so `ClientError` would be asked for by
     * a `419` and a `499` alike, and the body keeps `Error<status>`.
     */
    public static function componentName(string $status): ?string
    {
        $phrase = ReasonPhrase::registeredPhrase($status);

        return $phrase === null ? null : (string) preg_replace('/[^A-Za-z0-9]/', '', $phrase);
    }
}
