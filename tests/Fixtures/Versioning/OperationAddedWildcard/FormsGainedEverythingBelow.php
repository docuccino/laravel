<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OperationAddedWildcard;

use Docuccino\Attributes\Versioning\AddedOperation;
use Docuccino\Attributes\Versioning\ApiVersionChange;

/** A wildcard, which is the same grammar a route filter reads — one selector, several operations. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms grew a sub-resource.')]
#[AddedOperation('GET /api/versioned-forms/*')]
final class FormsGainedEverythingBelow {}
