<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Schema\Authentication;

/**
 * The INPUT half of a real app's permanent short-name collision: an app carries one
 * `SSOConnectionData` describing what a caller may send and another describing what it gets back.
 * Both are legitimate and both are published, so the two names have to be stable and tell them apart.
 */
final readonly class SSOConnectionData
{
    public function __construct(
        public string $issuerUrl,
        public string $clientSecret,
    ) {}
}
