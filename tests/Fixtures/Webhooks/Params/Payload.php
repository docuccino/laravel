<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Params;

use Docuccino\Attributes\Webhook;

#[Webhook('params.payload', payload: 'array{id: int, name: string}')]
final readonly class Payload {}
