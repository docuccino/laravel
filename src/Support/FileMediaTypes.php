<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * Filename extension → media type, for the file-backed responses whose Content-Type the server sniffs
 * from the file itself ({@see BinaryRepresentation}). Reading the extension off a literal path is
 * therefore not a guess about a different value — it is the same answer the sniff will reach.
 *
 * Deliberately a short list of formats an API hands back, not a full MIME database: an extension that
 * isn't here leaves the media type unresolved and the response falls back to octet-stream, which is
 * what the server sends when its own sniff fails too.
 */
final class FileMediaTypes
{
    /** @var array<string, string> */
    public const BY_EXTENSION = [
        'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'gif' => 'image/gif',
        'gz' => 'application/gzip',
        'html' => 'text/html',
        'ics' => 'text/calendar',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'json' => 'application/json',
        'jsonl' => 'application/jsonl',
        'md' => 'text/markdown',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'ndjson' => 'application/x-ndjson',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'svg' => 'image/svg+xml',
        'tar' => 'application/x-tar',
        'txt' => 'text/plain',
        'vcf' => 'text/vcard',
        'wav' => 'audio/wav',
        'webm' => 'video/webm',
        'webp' => 'image/webp',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xml' => 'application/xml',
        'yaml' => 'application/yaml',
        'yml' => 'application/yaml',
        'zip' => 'application/zip',
    ];

    /** The media type a literal path's extension names, or null when it has none we know. */
    public static function forPath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::BY_EXTENSION[$extension] ?? null;
    }

    /**
     * A `Content-Type` header value as a media-type key: the type itself, without the parameters a
     * header may carry. `text/csv; charset=utf-8` is a legal content key but a poor one — every consumer
     * matching on `text/csv` would miss it — and the charset is not part of what the body IS.
     */
    public static function normalize(string $header): ?string
    {
        $type = strtolower(trim(explode(';', $header, 2)[0]));

        return preg_match('#^[a-z0-9!\#$%&\'*+.^_`|~-]+/[a-z0-9!\#$%&\'*+.^_`|~-]+$#', $type) === 1 ? $type : null;
    }
}
