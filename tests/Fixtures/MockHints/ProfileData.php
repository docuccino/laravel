<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MockHints;

use Docuccino\Attributes\Mock;

/**
 * A response DTO whose properties carry mock hints, plus one attribute that can publish nothing — so
 * a build proves both halves at once: what reaches the document, and what only reaches a diagnostic.
 */
final readonly class ProfileData
{
    public function __construct(
        #[Mock(faker: 'uuid', seedGroup: 'profile')]
        public string $id,
        #[Mock(faker: 'safeEmail', seedGroup: 'profile')]
        public string $email,
        #[Mock]
        public string $nickname,
    ) {}
}
