<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Unreadable;

use DateTimeImmutable;
use Docuccino\Attributes\Versioning\ApiVersionChange;

/**
 * PHP refuses a closure in an attribute argument outright, but it permits `new`. The vocabulary's
 * scalar-only parameter types are what close that: this object cannot be kept in `string $since`, so
 * the declaration degrades to a diagnostic instead of being read. It is still CONSTRUCTED first —
 * `ArgumentEvaluated/` is the fixture for that half.
 */
#[ApiVersionChange(since: new DateTimeImmutable('2026-09-01'), description: 'Renames a form field.')]
final class UnreadableArgument {}
