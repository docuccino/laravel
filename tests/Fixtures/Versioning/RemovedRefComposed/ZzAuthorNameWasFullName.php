<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedRefComposed;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;

/**
 * An EARLIER change over the component the removal beside this one points at. Derivation rewrites the
 * WHOLE document, so the re-added field composes with this for free — and because this one is older,
 * the two versions between and below the pair see the same pointer resolve to two different shapes.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'An author publishes `name` where it published `full_name`.')]
#[RenamedResponseField(schema: AuthorData::class, from: 'full_name', to: 'name')]
final class ZzAuthorNameWasFullName {}
