<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Uncheckable;

use Docuccino\Attributes\Webhook;

/**
 * A finished export, delivered as the CSV itself rather than as JSON around it.
 *
 * The producer publishes the media type the attribute names, so the delivered body is one JSON Schema
 * cannot check — which is the honest degradation the delivery assertion notes rather than pretends to
 * have proved.
 */
#[Webhook('report.ready', mediaType: 'text/csv')]
final readonly class ReportReady
{
    public function __construct(
        public string $reference,
    ) {}
}
