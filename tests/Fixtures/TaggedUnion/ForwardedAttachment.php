<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

/** Wraps the attachment it forwards, so the sealed hierarchy refers back to itself. */
final readonly class ForwardedAttachment implements Attachment
{
    public AttachmentKind $kind;

    public function __construct(public Attachment $original, public string $from)
    {
        $this->kind = AttachmentKind::Forwarded;
    }
}
