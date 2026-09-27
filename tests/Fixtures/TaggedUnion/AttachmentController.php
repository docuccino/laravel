<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

/** Answers with attachments typed by the sealed interface, by a union, by a union one member leaves open, by a nullable union, and by a member that holds another attachment. */
final class AttachmentController
{
    /** @return list<Attachment> */
    public function index(): array
    {
        return [new ImageAttachment('https://example.com/a.png', 640, 480), new LinkAttachment('https://example.com')];
    }

    public function show(): ImageAttachment|LinkAttachment
    {
        return new LinkAttachment('https://example.com');
    }

    public function draft(): ImageAttachment|LinkAttachment|DraftAttachment
    {
        return new DraftAttachment(AttachmentKind::File, 'pending');
    }

    public function maybe(): ImageAttachment|LinkAttachment|null
    {
        return null;
    }

    public function forwarded(): ForwardedAttachment
    {
        return new ForwardedAttachment(new LinkAttachment('https://example.com'), 'someone@example.com');
    }
}
