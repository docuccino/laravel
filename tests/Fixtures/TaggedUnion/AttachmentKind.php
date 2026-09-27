<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

enum AttachmentKind: string
{
    case Image = 'image';
    case Link = 'link';
    case File = 'file';
    case Forwarded = 'forwarded';
}
