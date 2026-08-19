<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Collision;

use Docuccino\Attributes\Webhook;

#[Webhook('collision.claimed')]
final readonly class BetaClaim {}
