<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\ArgumentEvaluated;

use Docuccino\Attributes\Versioning\ApiVersionChange;

/**
 * The half of the foldability guarantee a docblock is tempted to overstate. PHP permits `new` in an
 * attribute argument and EVALUATES it, so this constructor runs before `string $since` gets to refuse
 * the object — the scalar-only types are a type guard, not an execution guard.
 */
#[ApiVersionChange(since: new ThrowsWhenConstructed, description: 'Never read.')]
final class EvaluatesArgument {}
