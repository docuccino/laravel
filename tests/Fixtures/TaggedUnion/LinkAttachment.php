<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

final readonly class LinkAttachment implements Attachment
{
    public AttachmentKind $kind;

    public function __construct(public string $href, public ?string $title = null)
    {
        $this->kind = AttachmentKind::Link;
    }
}
