<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OperationAddedShared;

use Docuccino\Attributes\Versioning\AddedOperation;
use Docuccino\Attributes\Versioning\ApiVersionChange;

/**
 * One of two paths that address ONE path item. Both operations are the same node, so removing it for
 * the named path would remove it for the path this change never mentioned — refused rather than
 * half-applied.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'One of the tree lists arrived.')]
#[AddedOperation('GET /api/versioned-trees/archived')]
final class OnlyOneSideOfASharedItem {}
