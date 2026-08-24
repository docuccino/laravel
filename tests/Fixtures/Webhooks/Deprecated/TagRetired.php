<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Deprecated;

use Docuccino\Attributes\Webhook;

/**
 * A tag was retired.
 *
 * @deprecated Replaced by label.retired.
 */
#[Webhook('tag.retired')]
final readonly class TagRetired {}
