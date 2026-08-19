<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * FQCNs of framework classes referenced by string across the adapter (never `use`d — they may be
 * absent from the analysed app). One home for a string that would otherwise be re-declared per
 * consumer, reachable from both the built-in extensions and the integrations.
 */
final class FrameworkClasses
{
    /** Illuminate's JSON response wrapper — the `JsonResponse<payload>` type integrations unwrap. */
    public const JSON_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    /** Illuminate's redirect. Always sets `Location`, and never carries a body of its own. */
    public const REDIRECT_RESPONSE = 'Illuminate\\Http\\RedirectResponse';

    /** Symfony's redirect, which Illuminate's extends. */
    public const REDIRECT_BASE = 'Symfony\\Component\\HttpFoundation\\RedirectResponse';

    /** Symfony's HttpFoundation response — the root every framework response class extends. */
    public const RESPONSE_BASE = 'Symfony\\Component\\HttpFoundation\\Response';

    /** Symfony's file response: `download()`/`file()` hand it back, and it serves a file from disk. */
    public const BINARY_FILE_RESPONSE = 'Symfony\\Component\\HttpFoundation\\BinaryFileResponse';

    /** Symfony's streamed response: a callback writes the body, so nothing about it is in the type. */
    public const STREAMED_RESPONSE = 'Symfony\\Component\\HttpFoundation\\StreamedResponse';

    /** Symfony's streamed JSON response, which `response()->streamJson()` hands back. */
    public const STREAMED_JSON_RESPONSE = 'Symfony\\Component\\HttpFoundation\\StreamedJsonResponse';

    /** The view contract `view()` is declared as returning; Laravel's own view implements it. */
    public const VIEW_CONTRACT = 'Illuminate\\Contracts\\View\\View';

    /**
     * The framework response classes an action is declared as returning. A response OBJECT is transport,
     * not an API contract — reflecting one documents PHP internals (`original`, `exception`, `headers`)
     * instead of the app. Deliberately not exhaustive: {@see isResponse()} also matches any loadable
     * subclass of the two hierarchy roots at the end, which are listed explicitly because
     * `is_subclass_of` does not match the class itself.
     *
     * @var list<string>
     */
    public const RESPONSE_CLASSES = [
        self::JSON_RESPONSE,
        self::REDIRECT_RESPONSE,
        'Illuminate\\Http\\Response',
        self::BINARY_FILE_RESPONSE,
        self::STREAMED_RESPONSE,
        self::STREAMED_JSON_RESPONSE,
        self::REDIRECT_BASE,
        self::RESPONSE_BASE,
    ];

    /**
     * The rendered-view classes an action is declared as returning. A view is transport too, but unlike a
     * response object it proves its own representation — HTML ({@see HtmlRepresentation}). Not exhaustive
     * either: {@see isView()} also matches any loadable implementation of the contract, which is listed
     * because `is_subclass_of` does not match the named type itself.
     *
     * @var list<string>
     */
    public const VIEW_CLASSES = [
        self::VIEW_CONTRACT,
        'Illuminate\\View\\View',
    ];

    /** Whether an FQCN names a framework response object rather than a body the API hands back. */
    public static function isResponse(string $fqcn): bool
    {
        return in_array($fqcn, self::RESPONSE_CLASSES, true)
            || is_subclass_of($fqcn, self::RESPONSE_BASE, true);
    }

    /** Whether an FQCN names a rendered view: transport that serves HTML rather than a body to reflect. */
    public static function isView(string $fqcn): bool
    {
        return in_array($fqcn, self::VIEW_CLASSES, true)
            || is_subclass_of($fqcn, self::VIEW_CONTRACT, true);
    }

    /**
     * Whether an FQCN names a response whose body is a FILE the server reads. What it proves is a body of
     * bytes: the server labels it from the file's own type at send time, falling back to octet-stream.
     */
    public static function isBinaryFile(string $fqcn): bool
    {
        return $fqcn === self::BINARY_FILE_RESPONSE || is_subclass_of($fqcn, self::BINARY_FILE_RESPONSE, true);
    }

    /**
     * Whether an FQCN names a response a callback writes. The streamed JSON response is one too, but it
     * fixes its own media type, so it is separated out rather than degrading with the rest.
     */
    public static function isStreamed(string $fqcn): bool
    {
        return $fqcn === self::STREAMED_RESPONSE || is_subclass_of($fqcn, self::STREAMED_RESPONSE, true);
    }

    /** Whether an FQCN names the streamed JSON response: a JSON body, streamed, of an unstated shape. */
    public static function isStreamedJson(string $fqcn): bool
    {
        return $fqcn === self::STREAMED_JSON_RESPONSE || is_subclass_of($fqcn, self::STREAMED_JSON_RESPONSE, true);
    }

    /** Whether an FQCN names a redirect: a 3xx carrying a `Location` header and no body. */
    public static function isRedirect(string $fqcn): bool
    {
        return $fqcn === self::REDIRECT_BASE
            || $fqcn === self::REDIRECT_RESPONSE
            || is_subclass_of($fqcn, self::REDIRECT_BASE, true);
    }
}
