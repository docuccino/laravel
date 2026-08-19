<?php

declare(strict_types=1);

namespace Workbench\App\Webhooks;

use Docuccino\Attributes\ExcludeFromDocs;
use Docuccino\Attributes\Webhook;

/**
 * A heartbeat kept for one remaining subscriber, and not part of the published contract.
 */
#[Webhook('legacy.ping')]
#[ExcludeFromDocs]
final readonly class LegacyPing {}
