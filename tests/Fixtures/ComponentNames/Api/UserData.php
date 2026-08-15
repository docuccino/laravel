<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Api;

use Docuccino\Attributes\SchemaId;

/**
 * A diff identity pinned to a name of the author's own, carrying no namespace — the documented way to
 * survive a class rename, and so exactly the shape the naming rule must not treat as unidentifiable.
 */
#[SchemaId('user.public.v1')]
final readonly class UserData
{
    public function __construct(
        public string $handle,
    ) {}
}
