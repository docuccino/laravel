<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

/**
 * Where a batch came from, as the audit trail records it. An abstract base rather than an interface
 * because these two members are data every origin carries; the subclasses differ only in how they were
 * obtained.
 */
abstract class BatchOrigin
{
    public function __construct(
        public readonly string $actor,
        public readonly int $attempt,
    ) {}
}
