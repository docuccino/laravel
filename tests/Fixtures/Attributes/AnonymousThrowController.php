<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Attributes;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\Summary;

/**
 * An attribute whose instantiation throws an ANONYMOUS exception: the argument runs `new` — legal in an
 * attribute's arguments since PHP 8.1 — and what it constructs raises one. `::class` on such an exception
 * spells the absolute file it was written in, which a published diagnostic may not carry.
 */
final class AnonymousThrowController
{
    /* @phpstan-ignore-next-line argument.type — the throwing argument IS the fixture */
    #[Group(new ThrowsAnonymously)]
    #[Summary('Still documented')]
    public function index(): array
    {
        return [];
    }
}
