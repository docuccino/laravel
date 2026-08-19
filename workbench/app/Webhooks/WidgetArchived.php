<?php

declare(strict_types=1);

namespace Workbench\App\Webhooks;

use Docuccino\Attributes\DeprecatedOperation;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\Webhook;
use Workbench\App\Data\WidgetData;

/**
 * A widget was archived.
 */
#[Webhook('widget.archived', method: 'put', payload: WidgetData::class)]
#[Group('Widgets')]
#[Response(status: 202, description: 'Queued for processing.')]
#[DeprecatedOperation]
final readonly class WidgetArchived {}
