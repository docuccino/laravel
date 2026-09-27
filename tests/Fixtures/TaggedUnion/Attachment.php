<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

/**
 * Something attached to a message.
 *
 * @phpstan-sealed ImageAttachment|LinkAttachment|FileAttachment|ForwardedAttachment
 */
interface Attachment {}
