<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Degraded;

use Docuccino\Attributes\Webhook;

#[Webhook('  ')]
final readonly class Nameless {}
