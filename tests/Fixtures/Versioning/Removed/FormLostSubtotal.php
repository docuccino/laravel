<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Removed;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/**
 * The plain removal: a field the code no longer has, put back with one of OpenAPI's own type names and
 * the sentence a consumer of the older version reads about it. Not required, so every example the
 * document publishes is still valid without it.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms no longer publish `subtotal`.')]
#[RemovedResponseField(
    schema: FormData::class,
    field: 'subtotal',
    type: 'integer',
    description: 'The form total before tax, in cents.',
)]
final class FormLostSubtotal {}
