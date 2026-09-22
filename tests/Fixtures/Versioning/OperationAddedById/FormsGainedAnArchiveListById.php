<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OperationAddedById;

use Docuccino\Attributes\Versioning\AddedOperation;
use Docuccino\Attributes\Versioning\ApiVersionChange;

/** The same operation, named by its operationId — the other spelling a selector reads. */
#[ApiVersionChange(since: '2026-09-01', description: 'Archived forms can be listed.')]
#[AddedOperation('listArchivedForms')]
final class FormsGainedAnArchiveListById {}
