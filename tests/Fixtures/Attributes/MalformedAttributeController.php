<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Attributes;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\Summary;

/**
 * Carries one attribute whose arguments don't fit its constructor — the typo a build must report
 * rather than swallow — beside a healthy one that must still apply.
 */
final class MalformedAttributeController
{
    /* @phpstan-ignore-next-line argument.type — the wrong argument type IS the fixture */
    #[Group(123)]
    #[Summary('Still documented')]
    public function index(): array
    {
        return [];
    }
}
