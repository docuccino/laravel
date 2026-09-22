<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OperationAddedEmpty;

use Docuccino\Attributes\Versioning\AddedOperation;
use Docuccino\Attributes\Versioning\ApiVersionChange;

/**
 * An addition that names no operation. Refused rather than read as a `*`, which is what an empty
 * selector would match — and would take every operation out of the document.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms grew something.')]
#[AddedOperation('  ')]
final class NamesNoOperation {}
