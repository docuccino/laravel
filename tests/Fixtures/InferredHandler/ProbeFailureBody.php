<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\InferredHandler;

/**
 * An error body a render callback builds as an object, so converting it hoists a component of its own —
 * and nothing else in the workbench names it, so whether that component survives is a fact about the one
 * response that built it.
 */
final class ProbeFailureBody
{
    public function __construct(
        public string $reason,
        public int $attempt,
    ) {}
}
