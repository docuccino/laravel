<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Lint;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\Webhook;

/**
 * An invoice was paid.
 */
#[Webhook('invoice.paid')]
#[Group('Billing')]
final readonly class InvoicePaid {}
