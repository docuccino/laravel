<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Deprecated;

use Docuccino\Attributes\DeprecatedOperation;
use Docuccino\Attributes\Webhook;

/**
 * A label was retired.
 */
#[Webhook('label.retired')]
#[DeprecatedOperation(reason: 'Replaced by taxonomy.changed.')]
final readonly class LabelRetired {}
