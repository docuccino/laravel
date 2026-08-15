<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Legacy;

/**
 * A third claimant on the same short name — the shape a deprecated endpoint still returns. Two is the
 * common case, but nothing about the naming may assume it.
 */
final readonly class SSOConnectionData
{
    public function __construct(
        public string $issuerUrl,
    ) {}
}
