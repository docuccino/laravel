<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

final readonly class FileAttachment implements Attachment
{
    public AttachmentKind $kind;

    public function __construct(public string $name, public int $bytes)
    {
        $this->kind = AttachmentKind::File;
    }
}
