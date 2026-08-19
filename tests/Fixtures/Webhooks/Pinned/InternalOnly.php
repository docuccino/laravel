<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Pinned;

use Docuccino\Attributes\InDocs;
use Docuccino\Attributes\Webhook;

#[Webhook('pinned.internal-only')]
#[InDocs('internal')]
final readonly class InternalOnly {}
