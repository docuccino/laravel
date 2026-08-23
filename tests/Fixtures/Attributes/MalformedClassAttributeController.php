<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Attributes;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\Summary;

/**
 * The class-level twin of {@see MalformedAttributeController}: the typo is on the CLASS, which the
 * collector reaches on a different walk than a method's, and the healthy neighbour must still apply.
 */
/* @phpstan-ignore-next-line argument.type — the wrong argument type IS the fixture */
#[Group(123)]
#[Summary('Still documented from the class')]
final class MalformedClassAttributeController
{
    public function index(): array
    {
        return [];
    }
}
