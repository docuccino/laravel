<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Versions\KeyRepointed;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\ParcelResource;

/**
 * Versions before the change sent the key as `internal_id`, and the tracking code under `id` — so `id`
 * names a different field in each.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A parcel sends its key as `id`, and its tracking code as `tracking`.')]
#[RenamedResponseField(schema: ParcelResource::class, from: 'internal_id', to: 'id')]
#[RenamedResponseField(schema: ParcelResource::class, from: 'id', to: 'tracking')]
final class KeyWasTheTrackingCode {}
