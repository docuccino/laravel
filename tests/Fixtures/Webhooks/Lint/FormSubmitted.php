<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Lint;

use Docuccino\Attributes\Webhook;

#[Webhook('1 form submitted!')]
final readonly class FormSubmitted {}
