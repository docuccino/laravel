<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Params;

use Docuccino\Attributes\Webhook;

#[Webhook('params.media-type', mediaType: 'application/cloudevents+json')]
final readonly class MediaType {}
