<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Params;

use Docuccino\Attributes\Webhook;

/**
 * A change was made.
 */
#[Webhook('params.defaults')]
final readonly class Defaults
{
    public function __construct(
        public string $reference,
    ) {}
}
