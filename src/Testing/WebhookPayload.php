<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonException;
use JsonSerializable;

/**
 * Whatever an application has at the moment it dispatches a webhook, as the JSON bytes it would send.
 *
 * One canonical path rather than a choice: a payload is taken in the form the code already holds it —
 * the event object, a Data object, an array, a `Jsonable`, or JSON text that is already encoded — and
 * `json_encode` decides the rest, so the contract check reads what the receiver would.
 */
final class WebhookPayload
{
    /**
     * A payload that will not encode is not a payload the application could have sent, so it throws
     * rather than degrading — the caller turns that into a failure naming the webhook.
     *
     * @throws JsonException when the value cannot be encoded as JSON
     */
    public static function json(mixed $payload): string
    {
        return match (true) {
            // Already the bytes. A string that is not JSON fails the check as the malformed body it is.
            is_string($payload) => $payload,
            $payload instanceof Jsonable => (string) $payload->toJson(),
            // Ahead of Arrayable deliberately: an object that says what its JSON is outranks one that
            // says what its array is, and json_encode() honours jsonSerialize() on the way through.
            $payload instanceof JsonSerializable => self::encode($payload),
            $payload instanceof Arrayable => self::encode($payload->toArray()),
            default => self::encode($payload),
        };
    }

    /**
     * Whether a `[]` in those bytes could as easily have been `{}`.
     *
     * True of everything {@see json()} encodes, because PHP's one array is both containers and
     * `json_encode` has to pick — and false of the one branch that encodes nothing: a payload handed
     * over as JSON text already says which it is, in its own bytes.
     */
    public static function emptyIsAmbiguous(mixed $payload): bool
    {
        return ! is_string($payload);
    }

    /**
     * @throws JsonException
     */
    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
