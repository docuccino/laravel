<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * How Laravel's stock exceptions map to HTTP responses. Every error tier reads this one table — the
 * plain-JSON framework-errors tier, the RFC 9457 Problem Details preset, the terminal fallback and the
 * inferred-handler builder — so no two presentations can drift on a status or its label.
 *
 * Reason phrases are the RFC 9110 §15 canonical ones, used verbatim as the framework-error response
 * description and the problem-details title. Note 401 is "Unauthorized" (§15.5.2), not
 * "Unauthenticated" — Laravel's own message wording is not the reason phrase.
 */
final class FrameworkExceptionTable
{
    /**
     * The status an error carrying no readable status of its own is published under. It is not a claim
     * about the exception — nothing read one — but a key the document cannot do without, since a response
     * is addressed by status and there is no other key to give it. Shared because more than one tier
     * reaches for it (the terminal fallback, and the Problem Details preset for an `HttpException` whose
     * status did not fold), and two tiers keying one error differently would publish two responses where
     * the server sends one.
     */
    public const UNPLACED_STATUS = '500';

    /**
     * Base exception FQCN → its HTTP status and whether it carries a field-keyed `errors` map (the
     * validation shape). Matched subtype-aware, so a subclass inherits its base's mapping.
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
        'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException' => ['status' => '404', 'validation' => false],
    ];

    /**
     * HTTP status → RFC 9110 §15 reason phrase. Covers every status the error tiers can emit; anything
     * unlisted degrades to a generic `Error`. Typed `array<int, string>` because PHP coerces the
     * numeric-string keys to int.
     *
     * @var array<int, string>
     */
    private const REASON_PHRASES = [
        '400' => 'Bad Request',
        '401' => 'Unauthorized',
        '403' => 'Forbidden',
        '404' => 'Not Found',
        '405' => 'Method Not Allowed',
        '409' => 'Conflict',
        '422' => 'Unprocessable Entity',
        '429' => 'Too Many Requests',
        '500' => 'Internal Server Error',
        '503' => 'Service Unavailable',
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

    /** The reason phrase for a status, or a generic `Error` when unlisted. */
    public static function reason(string $status): string
    {
        return self::REASON_PHRASES[$status] ?? 'Error';
    }

    /**
     * The component name an error body for this status is published under — the reason phrase as one
     * word, so what a client catches is called `NotFound` rather than `Error404`. Null for a status
     * with no phrase of its own: `Error` names nothing, and every unlisted status would claim it.
     */
    public static function componentName(string $status): ?string
    {
        $phrase = self::REASON_PHRASES[$status] ?? null;

        return $phrase === null ? null : str_replace(' ', '', $phrase);
    }

    /**
     * The `[status, phrase]` pairs, for the dataset test over every entry. A list of pairs rather than a
     * map because PHP coerces the numeric-string keys back to int.
     *
     * @return list<array{string, string}>
     */
    public static function reasonPhrases(): array
    {
        $out = [];
        foreach (self::REASON_PHRASES as $status => $phrase) {
            $out[] = [(string) $status, $phrase];
        }

        return $out;
    }
}
