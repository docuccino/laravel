<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OperationAddedMissing;

use Docuccino\Attributes\Versioning\AddedOperation;
use Docuccino\Attributes\Versioning\ApiVersionChange;

/** A selector naming an operation the document does not publish, so there is nothing to take out. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms grew a route that has since moved.')]
#[AddedOperation('DELETE /api/versioned-forms/archived')]
final class FormsGainedSomethingElse {}
