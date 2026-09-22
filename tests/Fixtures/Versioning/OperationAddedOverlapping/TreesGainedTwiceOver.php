<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OperationAddedOverlapping;

use Docuccino\Attributes\Versioning\AddedOperation;
use Docuccino\Attributes\Versioning\ApiVersionChange;

/**
 * Two selectors that overlap. The wildcard removes the archived list, and the specific selector names
 * the same operation — which the change itself has just taken out. Matched against the document the
 * CHANGE found rather than the one its own sibling left, neither reports anything.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Archived trees can be listed.')]
#[AddedOperation('GET /api/versioned-trees/*')]
#[AddedOperation('GET /api/versioned-trees/archived')]
final class TreesGainedTwiceOver {}
