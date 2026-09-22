<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OperationAdded;

use Docuccino\Attributes\Versioning\AddedOperation;
use Docuccino\Attributes\Versioning\ApiVersionChange;

/** An operation this version added, named by the signature the document publishes it under. */
#[ApiVersionChange(since: '2026-09-01', description: 'Archived forms can be listed.')]
#[AddedOperation('GET /api/versioned-forms/archived')]
final class FormsGainedAnArchiveList {}
