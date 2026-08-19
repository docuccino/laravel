<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Locality\Anchor;

use Docuccino\Attributes\Webhook;

/**
 * The anchor a neighbouring webhook must not move.
 */
#[Webhook('locality.anchor')]
final readonly class Anchor
{
    public function __construct(
        public int $id,
    ) {}
}
