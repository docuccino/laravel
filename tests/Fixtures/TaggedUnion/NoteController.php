<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

use Docuccino\Attributes\BodyParameter;

/** Takes a note, an attachment and a pinned link, and sends each back on a route of its own. */
final class NoteController
{
    #[BodyParameter(name: 'attachment', type: Attachment::class)]
    #[BodyParameter(name: 'note', type: Note::class)]
    #[BodyParameter(name: 'pinned', type: PinnedLinkData::class)]
    public function store(): void {}

    public function attachment(): Attachment
    {
        return new LinkAttachment('https://example.com');
    }

    public function note(): Note
    {
        return new Note('see', new LinkAttachment('https://example.com'));
    }

    public function pinned(): PinnedLinkData
    {
        return new PinnedLinkData('docs', new LinkAttachment('https://example.com'));
    }
}
