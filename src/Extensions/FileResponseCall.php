<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

/**
 * What one recovered file/stream factory call proves about the response it hands back: the class, the
 * media type and body schema it serves, and the `Content-Disposition` it sets — null where the call sets
 * none, which is a fact about the call and not a failure to read it.
 */
final readonly class FileResponseCall
{
    /** Saved as a file rather than displayed. */
    public const ATTACHMENT = 'attachment';

    /** Displayed rather than saved. */
    public const INLINE = 'inline';

    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public string $responseClass,
        public string $mediaType,
        public array $schema,
        public ?string $disposition = null,
        public ?string $filename = null,
    ) {}
}
