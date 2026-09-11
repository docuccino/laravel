<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/**
 * Carries its own property and no map attribute at all: the key it publishes is decided entirely by
 * {@see MappedAncestorData}.
 */
final class MappedDescendantData extends MappedAncestorData
{
    public function __construct(
        public string $displayName,
    ) {}
}
