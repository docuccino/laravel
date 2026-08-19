<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Params;

use Docuccino\Attributes\Webhook;

#[Webhook('params.method', method: 'PATCH')]
final readonly class Method {}
