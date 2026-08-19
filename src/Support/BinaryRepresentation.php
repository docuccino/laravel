<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * The one way the adapter says "this endpoint answers with a file or a stream": an opaque `string` body
 * with `format: binary`, under whatever media type the call proves. A download has no structure the code
 * can describe, so the schema says only how to receive it.
 *
 * The two fallbacks are different facts, not two spellings of one. A FILE body falls back to
 * {@see OCTET_STREAM}, because that is what the server itself sends when its own sniff of the file
 * fails. A CALLBACK body falls back to the {@see ANY_MEDIA_TYPE} range, because nothing states a type
 * anywhere — Symfony ends up labelling it `text/html`, so naming a concrete type would be a confident
 * wrong answer.
 */
final class BinaryRepresentation
{
    /** A file body whose media type nothing named — the server's own fallback. */
    public const OCTET_STREAM = 'application/octet-stream';

    /** A streamed body whose media type nothing named at all. */
    public const ANY_MEDIA_TYPE = '*/*';

    /**
     * The body schema. `format: binary` is what a client generator reads to hand back bytes or a stream
     * rather than a decoded value.
     *
     * @var array<string, mixed>
     */
    public const SCHEMA = ['type' => 'string', 'format' => 'binary'];
}
