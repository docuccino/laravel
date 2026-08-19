<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Pinned;

use Docuccino\Attributes\Webhook;

#[Webhook('pinned.everywhere')]
final readonly class Everywhere {}
