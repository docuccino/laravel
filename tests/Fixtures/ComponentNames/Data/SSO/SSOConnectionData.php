<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Data\SSO;

/**
 * The OUTPUT half of the same collision — see the input one under `Schema\Authentication`. A
 * different shape: it never echoes the secret back, and it carries state the caller cannot set.
 */
final readonly class SSOConnectionData
{
    public function __construct(
        public string $issuerUrl,
        public bool $verified,
    ) {}
}
