<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OperationAddedThenRenamed;

use Docuccino\Attributes\Versioning\AddedOperation;
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;

/**
 * A change that renames a parameter of the ONE operation it also says this version added. What it
 * proves is the order: the rename runs against the document the code publishes and applies silently,
 * and the operation goes afterwards. Run the other way round the rename would find no operation
 * declaring the parameter and report a declaration that is nothing of the kind.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms search arrived, taking `q`.')]
#[RenamedParameter(in: 'query', from: 'q', to: 'search')]
#[AddedOperation('GET /api/versioned-search')]
final class SearchArrivedTakingQ {}
