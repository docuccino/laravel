<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

/** Takes its kind from the caller, so it may hold any of them. */
final readonly class DraftAttachment
{
    public function __construct(public AttachmentKind $kind, public string $note) {}
}
