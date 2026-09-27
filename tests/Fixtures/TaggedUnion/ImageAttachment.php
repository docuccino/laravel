<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

final readonly class ImageAttachment implements Attachment
{
    public AttachmentKind $kind;

    public function __construct(public string $url, public int $width, public int $height)
    {
        $this->kind = AttachmentKind::Image;
    }
}
