<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Spatie\LaravelData\Data;

/**
 * A nested Data class the {@see DataSchema} mapper hoists
 * as its own component when referenced from another Data class.
 */
final class AuthorData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
