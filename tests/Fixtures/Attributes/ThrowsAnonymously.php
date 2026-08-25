<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Attributes;

use RuntimeException;

/**
 * Raises an exception whose class is ANONYMOUS. Constructed from inside an attribute's argument list,
 * which PHP has allowed `new` in since 8.1 — so what an attribute's instantiation can throw is not
 * limited to the named errors PHP raises itself.
 */
final class ThrowsAnonymously
{
    public function __construct()
    {
        throw new class extends RuntimeException {};
    }
}
