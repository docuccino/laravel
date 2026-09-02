<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * An export the service refused. Everything the problem document says about it — the reason, the instant,
 * whether another attempt is worth making and whatever the service attached — is a property of the failure
 * rather than of the endpoint, so no render arm can write any of them out.
 */
final class ExportRefusedException extends RuntimeException
{
    public function __construct(
        private readonly ExportFailure $reason,
        private readonly CarbonImmutable $failedAt,
        private readonly bool $retryable = false,
        /** @var array<string, mixed> */
        private readonly array $context = [],
    ) {
        parent::__construct('The export was refused.');
    }

    public function reason(): ExportFailure
    {
        return $this->reason;
    }

    public function failedAt(): CarbonImmutable
    {
        return $this->failedAt;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
