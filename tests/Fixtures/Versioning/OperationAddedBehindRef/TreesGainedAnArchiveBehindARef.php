<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OperationAddedBehindRef;

use Docuccino\Attributes\Versioning\AddedOperation;
use Docuccino\Attributes\Versioning\ApiVersionChange;

/** An operation published through a path item written as a `$ref` into the components. */
#[ApiVersionChange(since: '2026-09-01', description: 'Archived trees can be listed.')]
#[AddedOperation('GET /api/versioned-trees/archived')]
final class TreesGainedAnArchiveBehindARef {}
